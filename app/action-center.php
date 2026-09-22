<?php
declare(strict_types=1);

require_once __DIR__.'/cognitive-feed.php';
require_once __DIR__.'/research-workflow.php';

function action_center_definitions(): array {
    return [
      'confirm'=>['label'=>'Confirm','description'=>'Explicit writes waiting for your approval.'],
      'respond'=>['label'=>'Respond','description'=>'People or reviews waiting on you.'],
      'review'=>['label'=>'Review','description'=>'Changes, conflicts, and evidence risks that need human attention.'],
      'continue'=>['label'=>'Continue','description'=>'Current Research work with a concrete next step.'],
    ];
}

function action_center_kind_for_type(string $type): ?string {
    return match($type){
      'pending_agent_action'=>'confirm',
      'team_activity','review_requested','review_overdue'=>'respond',
      'source_change','research_gap','research_conflict','related_conflict','review_stale','review_changes_requested','review_objection','change_impact'=>'review',
      'model_health_incident'=>'review',
      'model_improvement_case'=>'review',
      'research_task'=>'continue',
      default=>null
    };
}

function action_center_urgency(array $row,string $kind): string {
    $priority=(string)($row['priority']??'medium');
    if($kind==='confirm'||$priority==='high')return 'high';
    if($priority==='low')return 'low';
    return 'medium';
}

function action_center_workspace_from_href(string $href): array {
    if($href==='')return [];
    $parts=parse_url($href);if(!$parts)return [];$path=(string)($parts['path']??'');$query=[];parse_str((string)($parts['query']??''),$query);$out=[];
    if(isset($query['team']))$out['team_public_id']=(string)$query['team'];
    if(isset($query['project']))$out['research_public_id']=(string)$query['project'];
    if(isset($query['agent']))$out['agent_conversation_public_id']=(string)$query['agent'];
    if($path==='/team.php'&&isset($query['id']))$out['team_public_id']=(string)$query['id'];
    if($path==='/research-project.php'&&isset($query['id']))$out['research_public_id']=(string)$query['id'];
    if($path==='/research-claim.php'&&isset($query['id'])){$out['object_type']='claim';$out['object_public_id']=(string)$query['id'];}
    if($path==='/research-finding.php'&&isset($query['id'])){$out['object_type']='finding';$out['object_public_id']=(string)$query['id'];}
    if($path==='/annotation.php'&&isset($query['id'])){$out['object_type']='annotation';$out['object_public_id']=(string)$query['id'];}
    if($path==='/source.php'&&isset($query['id'])){$out['object_type']='source';$out['object_public_id']=(string)$query['id'];}
    return $out;
}

function action_center_workspace_from_actions(array $actions): array {
    $workspace=[];
    foreach($actions as $action){
        if(($action['type']??'')==='link'){
            foreach(action_center_workspace_from_href((string)($action['href']??'')) as $key=>$value)if($value!==''&&!isset($workspace[$key]))$workspace[$key]=$value;
            continue;
        }
        if(($action['type']??'')!=='agent')continue;
        foreach((array)($action['context']??[]) as $context){
            $type=(string)($context['type']??'');$id=(string)($context['public_id']??'');if($id==='')continue;
            if($type==='research'&&!isset($workspace['research_public_id']))$workspace['research_public_id']=$id;
            elseif($type==='team'&&!isset($workspace['team_public_id']))$workspace['team_public_id']=$id;
            elseif(in_array($type,['annotation','source'],true)&&!isset($workspace['object_public_id'])){$workspace['object_type']=$type;$workspace['object_public_id']=$id;}
        }
    }
    return $workspace;
}

function action_center_primary_action(array $actions): ?array {
    foreach($actions as $action)if(($action['type']??'')==='link'&&!empty($action['href']))return $action;
    foreach($actions as $action)if(($action['type']??'')==='agent')return $action;
    return null;
}

function action_center_from_cognitive(array $row): ?array {
    $kind=action_center_kind_for_type((string)($row['type']??''));if($kind===null)return null;
    $actions=array_values(array_filter((array)($row['actions']??[]),fn($a)=>is_array($a)&&in_array((string)($a['type']??''),['link','agent'],true)));
    if(!$actions)return null;
    return [
      'key'=>(string)$row['key'],'kind'=>$kind,'source_type'=>(string)$row['type'],
      'urgency'=>action_center_urgency($row,$kind),'score'=>(int)($row['score']??0),
      'created_at'=>$row['created_at']??null,'title'=>(string)($row['title']??'Action needed'),'body'=>(string)($row['body']??''),
      'meta'=>(array)($row['meta']??[]),'actions'=>$actions,'primary_action'=>action_center_primary_action($actions),
      'workspace'=>action_center_workspace_from_actions($actions)
    ];
}

function action_center_project_workflow_items(PDO $pdo,array $viewer,array $existing): array {
    if(!function_exists('research_workflow_state'))return [];
    $q=$pdo->prepare("SELECT DISTINCT rp.public_id,rp.updated_at FROM research_projects rp LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=? WHERE rp.owner_user_id=? OR tm.user_id=? ORDER BY rp.updated_at DESC LIMIT 24");
    $q->execute([$viewer['id'],$viewer['id'],$viewer['id']]);$out=[];$covered=[];
    foreach($existing as $row){$project=(string)($row['workspace']['research_public_id']??'');if($project!=='')$covered[$project]=true;}
    foreach($q->fetchAll() as $projectRow){
        $projectPublic=(string)$projectRow['public_id'];if(isset($covered[$projectPublic]))continue;
        $state=research_workflow_state($pdo,$viewer,$projectPublic);if(empty($state['available']))continue;$next=(array)($state['next']??[]);$stage=(string)($next['stage']??'');
        if($stage===''||($stage==='monitor'&&(string)($next['title']??'')==='Research loop is healthy'))continue;
        $url=(string)($next['url']??('/research-project.php?id='.rawurlencode($projectPublic)));
        $actions=[cognitive_feed_action_link('Continue',$url)];
        if(!empty($next['agent_prompt']))$actions[]=cognitive_feed_action_agent('Ask Agent',(string)$next['agent_prompt'],[['type'=>'research','public_id'=>$projectPublic]]);
        $out[]=[
          'key'=>'workflow:'.$projectPublic.':'.$stage,'kind'=>'continue','source_type'=>'research_workflow','urgency'=>in_array($stage,['verify','review','monitor'],true)?'medium':'low',
          'score'=>in_array($stage,['verify','review','monitor'],true)?58:48,'created_at'=>$projectRow['updated_at'],
          'title'=>(string)($next['title']??'Continue Research'),'body'=>(string)($next['detail']??''),
          'meta'=>['project'=>(string)($state['project']['title']??$projectPublic),'stage'=>$stage],
          'actions'=>$actions,'primary_action'=>$actions[0],'workspace'=>['research_public_id'=>$projectPublic]
        ];
    }
    return $out;
}

function action_center_compose(PDO $pdo,array $viewer,?array $teamList=null,int $limit=60,?array $cognitiveBase=null): array {
    $limit=max(4,min(100,$limit));$base=$cognitiveBase??cognitive_feed_items($pdo,$viewer,$teamList,false);
    if(empty($base['ready']))return ['ready'=>false,'groups'=>[],'items'=>[],'total'=>0,'high_count'=>0,'counts'=>[]];
    $items=[];foreach((array)$base['items'] as $row){$item=action_center_from_cognitive($row);if($item)$items[$item['key']]=$item;}
    foreach(action_center_project_workflow_items($pdo,$viewer,array_values($items)) as $item)if(!isset($items[$item['key']]))$items[$item['key']]=$item;
    $rows=array_values($items);usort($rows,function($a,$b){$urg=['high'=>3,'medium'=>2,'low'=>1];$u=($urg[$b['urgency']]??0)<=>($urg[$a['urgency']]??0);if($u!==0)return $u;$s=((int)$b['score'])<=>((int)$a['score']);if($s!==0)return $s;return (strtotime((string)($b['created_at']??''))?:0)<=>(strtotime((string)($a['created_at']??''))?:0);});$rows=array_slice($rows,0,$limit);
    $defs=action_center_definitions();$buckets=[];$counts=array_fill_keys(array_keys($defs),0);$high=0;
    foreach($rows as $row){$counts[$row['kind']]++;if($row['urgency']==='high')$high++;$buckets[$row['kind']][]=$row;}
    $groups=[];foreach($defs as $key=>$def)if(!empty($buckets[$key]))$groups[]=['key'=>$key,'label'=>$def['label'],'description'=>$def['description'],'items'=>$buckets[$key]];
    return ['ready'=>true,'groups'=>$groups,'items'=>$rows,'total'=>count($rows),'high_count'=>$high,'counts'=>$counts,'hidden_count'=>(int)($base['hidden_count']??0),'ranking'=>'Deterministic routing from current authoritative Annotated state. Completing the underlying work removes the action automatically.'];
}
