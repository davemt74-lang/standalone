<?php
declare(strict_types=1);

require_once __DIR__.'/feed.php';
require_once __DIR__.'/research-workspace.php';
require_once __DIR__.'/conversations.php';
require_once __DIR__.'/agent-actions.php';

function cognitive_feed_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'cognitive_feed_dismissals');}
    catch(Throwable $e){return false;}
}

function cognitive_feed_mode_get(PDO $pdo,array $viewer): string {
    if(!cognitive_feed_ready($pdo))return 'latest';
    try{$q=$pdo->prepare('SELECT home_feed_mode FROM user_preferences WHERE user_id=?');$q->execute([$viewer['id']]);$mode=(string)($q->fetchColumn()?:'cognitive');}
    catch(Throwable $e){$mode='cognitive';}
    return in_array($mode,['cognitive','latest'],true)?$mode:'cognitive';
}

function cognitive_feed_mode_set(PDO $pdo,array $viewer,string $mode): string {
    $mode=strtolower(trim($mode));if(!in_array($mode,['cognitive','latest'],true))throw new InvalidArgumentException('Invalid Home feed mode.');
    if(!cognitive_feed_ready($pdo))throw new RuntimeException('Cognitive Feed requires the Phase 16 database upgrade.');
    $pdo->prepare("INSERT INTO user_preferences(user_id,home_feed_mode) VALUES(?,?) ON DUPLICATE KEY UPDATE home_feed_mode=VALUES(home_feed_mode)")->execute([$viewer['id'],$mode]);
    return $mode;
}

function cognitive_feed_project_writable(array $project): bool {
    return in_array((string)($project['access_role']??''),['owner','admin','researcher'],true);
}

function cognitive_feed_projects(PDO $pdo,array $viewer,int $limit=8): array {
    $limit=max(1,min(20,$limit));$uid=(int)$viewer['id'];
    $q=$pdo->prepare("SELECT DISTINCT rp.*,CASE WHEN rp.owner_user_id=? THEN 'owner' ELSE COALESCE(tm.role,'viewer') END access_role
      FROM research_projects rp
      LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=?
      WHERE rp.status='active' AND (rp.owner_user_id=? OR tm.user_id=?)
      ORDER BY rp.updated_at DESC,rp.id DESC LIMIT $limit");
    $q->execute([$uid,$uid,$uid,$uid]);return $q->fetchAll();
}

function cognitive_feed_key(string $type,string $objectType,string $objectId,string $revision=''): string {
    return hash('sha256',$type.'|'.$objectType.'|'.$objectId.'|'.$revision);
}

function cognitive_feed_priority_bonus(string $priority): int {
    return match($priority){'high'=>8,'medium'=>4,'low'=>0,default=>2};
}

function cognitive_feed_recency_bonus(?string $createdAt): int {
    if(!$createdAt)return 0;$ts=strtotime($createdAt);if(!$ts)return 0;$hours=max(0,(time()-$ts)/3600);
    if($hours<=6)return 8;if($hours<=24)return 6;if($hours<=72)return 4;if($hours<=168)return 2;return 0;
}

function cognitive_feed_base_score(string $type): int {
    return match($type){
      'pending_agent_action'=>92,
      'research_conflict'=>88,
      'related_conflict'=>86,
      'source_change'=>82,
      'research_gap'=>78,
      'new_evidence'=>68,
      'research_opportunity'=>64,
      'research_task'=>62,
      'team_activity'=>58,
      'related_research'=>56,
      'recent_change'=>48,
      default=>45
    };
}

function cognitive_feed_score(string $type,string $priority='medium',?string $createdAt=null,int $extra=0): int {
    $cap=$type==='pending_agent_action'?100:98;
    return max(0,min($cap,cognitive_feed_base_score($type)+cognitive_feed_priority_bonus($priority)+cognitive_feed_recency_bonus($createdAt)+$extra));
}

function cognitive_feed_observation(array $data): array {
    $type=(string)($data['type']??'observation');$priority=(string)($data['priority']??'medium');$created=$data['created_at']??null;
    $data['section']=(string)($data['section']??'recent_changes');
    $data['priority']=$priority;$data['created_at']=$created;
    $data['score']=isset($data['score'])?(int)$data['score']:cognitive_feed_score($type,$priority,$created,(int)($data['score_extra']??0));
    unset($data['score_extra']);
    $data['actions']=is_array($data['actions']??null)?$data['actions']:[];
    return $data;
}

function cognitive_feed_dismissed_keys(PDO $pdo,int $userId): array {
    if(!cognitive_feed_ready($pdo))return [];
    $q=$pdo->prepare('SELECT observation_key FROM cognitive_feed_dismissals WHERE user_id=?');$q->execute([$userId]);
    return array_fill_keys(array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN)),true);
}

function cognitive_feed_dismiss(PDO $pdo,array $viewer,string $key,string $type): void {
    if(!cognitive_feed_ready($pdo))throw new RuntimeException('Cognitive Feed requires the Phase 16 database upgrade.');
    $key=strtolower(trim($key));if(!preg_match('/^[a-f0-9]{64}$/',$key))throw new InvalidArgumentException('Invalid cognitive feed item.');
    $type=mb_substr(trim($type),0,64);if($type==='')$type='observation';
    $pdo->prepare('INSERT INTO cognitive_feed_dismissals(user_id,observation_key,observation_type) VALUES(?,?,?) ON DUPLICATE KEY UPDATE observation_type=VALUES(observation_type),dismissed_at=NOW()')->execute([$viewer['id'],$key,$type]);
    $q=$pdo->prepare('SELECT COUNT(*) FROM cognitive_feed_dismissals WHERE user_id=?');$q->execute([$viewer['id']]);$excess=max(0,(int)$q->fetchColumn()-500);
    if($excess>0)$pdo->prepare("DELETE FROM cognitive_feed_dismissals WHERE user_id=? ORDER BY dismissed_at ASC LIMIT $excess")->execute([$viewer['id']]);
    if(function_exists('research_outcomes_ready')&&research_outcomes_ready($pdo)){try{research_outcome_sync_dismissals($pdo,$viewer,20);}catch(Throwable $ignored){}}
}

function cognitive_feed_restore(PDO $pdo,array $viewer,string $key): bool {
    if(!cognitive_feed_ready($pdo))return false;$key=strtolower(trim($key));if(!preg_match('/^[a-f0-9]{64}$/',$key))throw new InvalidArgumentException('Invalid cognitive feed item.');
    $q=$pdo->prepare('DELETE FROM cognitive_feed_dismissals WHERE user_id=? AND observation_key=?');$q->execute([$viewer['id'],$key]);return $q->rowCount()>0;
}

function cognitive_feed_restore_all(PDO $pdo,array $viewer): int {
    if(!cognitive_feed_ready($pdo))return 0;$q=$pdo->prepare('DELETE FROM cognitive_feed_dismissals WHERE user_id=?');$q->execute([$viewer['id']]);return $q->rowCount();
}

function cognitive_feed_hidden_count(PDO $pdo,array $viewer): int {
    if(!cognitive_feed_ready($pdo))return 0;$q=$pdo->prepare('SELECT COUNT(*) FROM cognitive_feed_dismissals WHERE user_id=?');$q->execute([$viewer['id']]);return (int)$q->fetchColumn();
}

function cognitive_feed_action_link(string $label,string $href): array {
    return ['type'=>'link','label'=>$label,'href'=>$href];
}

function cognitive_feed_action_agent(string $label,string $prompt,array $context=[]): array {
    return ['type'=>'agent','label'=>$label,'prompt'=>$prompt,'context'=>$context];
}

function cognitive_feed_add(array &$items,array $item): void {
    $item=cognitive_feed_observation($item);$key=(string)($item['key']??'');if($key==='')return;
    if(!isset($items[$key])||($item['score']??0)>($items[$key]['score']??0))$items[$key]=$item;
}

function cognitive_feed_section_definitions(): array {
    return [
      'needs_attention'=>['label'=>'Needs attention','description'=>'Unresolved items that may affect your research.'],
      'new_evidence'=>['label'=>'New evidence','description'=>'Recent evidence added to Research you can access.'],
      'source_changed'=>['label'=>'Source changed','description'=>'Monitored or project sources that changed after capture.'],
      'related_research'=>['label'=>'Related to your Research','description'=>'Evidence relationships connected to active projects.'],
      'opportunities'=>['label'=>'Opportunities','description'=>'Useful next moves identified from current Research state.'],
      'continue_researching'=>['label'=>'Continue researching','description'=>'Open work worth picking back up.'],
      'team_activity'=>['label'=>'Team activity','description'=>'Unread collaboration that may need your attention.'],
      'recent_changes'=>['label'=>'Recent changes','description'=>'Meaningful updates across your Annotated workspace.'],
    ];
}


function cognitive_feed_collect_pending_actions(PDO $pdo,array $viewer,array &$items): void {
    if(!agent_actions_ready($pdo))return;
    $q=$pdo->prepare("SELECT aap.public_id,aap.capability_key,aap.created_at,aap.expires_at,rp.public_id project_public_id,rp.title project_title,c.public_id conversation_public_id
      FROM agent_action_proposals aap
      JOIN research_projects rp ON rp.id=aap.project_id
      JOIN conversations c ON c.id=aap.conversation_id
      WHERE aap.proposed_by_user_id=? AND aap.status='pending' AND aap.expires_at>NOW()
      ORDER BY aap.created_at DESC LIMIT 8");
    $q->execute([$viewer['id']]);$caps=agent_action_capabilities();
    foreach($q->fetchAll() as $row){
        $project=project_access($pdo,(int)$viewer['id'],(string)$row['project_public_id']);if(!$project||!cognitive_feed_project_writable($project))continue;
        $label=(string)($caps[$row['capability_key']]['label']??'Research action');
        cognitive_feed_add($items,[
          'key'=>cognitive_feed_key('pending_agent_action','proposal',(string)$row['public_id']),
          'type'=>'pending_agent_action','section'=>'needs_attention','priority'=>'high','created_at'=>$row['created_at'],
          'title'=>'Research action waiting for confirmation',
          'body'=>$label.' in '.$row['project_title'].' is still pending. Review the exact write before it expires.',
          'meta'=>['project'=>$row['project_title'],'expires_at'=>$row['expires_at']],
          'actions'=>array_values(array_filter([
            cognitive_feed_action_link('Review in Agent','/home.php?agent='.rawurlencode((string)$row['conversation_public_id'])),
            function_exists('research_reviews_ready')&&research_reviews_ready($pdo)?cognitive_feed_action_link('Request Team Review','/research-reviews.php?type=agent_action&subject='.rawurlencode((string)$row['public_id'])):null,
            cognitive_feed_action_link('Open Research','/research-project.php?id='.rawurlencode((string)$row['project_public_id']))
          ]))
        ]);
    }
}

function cognitive_feed_collect_team_activity(PDO $pdo,array $viewer,array &$items,?array $teamList=null): void {
    if(!conversation_runtime_ready($pdo))return;
    foreach($teamList??conversation_team_list($pdo,$viewer) as $team){
        $unread=(int)($team['unread_count']??0);if($unread<1)continue;
        $created=(string)($team['last_message_at']??'');$revision=$created!==''?$created:(string)$unread;
        cognitive_feed_add($items,[
          'key'=>cognitive_feed_key('team_activity','conversation',(string)$team['public_id'],$revision),
          'type'=>'team_activity','section'=>'team_activity','priority'=>$unread>=5?'high':'medium','created_at'=>$created,
          'score_extra'=>min(8,$unread),
          'title'=>$unread.' unread '.($unread===1?'message':'messages').' in '.$team['team_name'],
          'body'=>trim((string)($team['last_message']??''))!==''?'Latest: '.mb_substr((string)$team['last_message'],0,220):'Your team has new activity.',
          'meta'=>['team'=>$team['team_name'],'unread'=>$unread],
          'actions'=>[
            cognitive_feed_action_link('Open Team Chat','/home.php?team='.rawurlencode((string)$team['team_public_id']).'#team-chat'),
            cognitive_feed_action_link('Open Team','/team.php?id='.rawurlencode((string)$team['team_public_id']))
          ]
        ]);
    }
}

function cognitive_feed_collect_watched_source_changes(PDO $pdo,array $viewer,array &$items): void {
    $admin=(($viewer['role']??'')==='admin');$sql="SELECT sce.id,sce.created_at,sce.change_type,sce.impact_type,sce.target_changed,sce.affected_annotation_count,sce.diff_summary,
      s.public_id source_public_id,s.title,s.domain
      FROM source_change_events sce
      JOIN source_watches sw ON sw.source_id=sce.source_id AND sw.user_id=?
      JOIN sources s ON s.id=sce.source_id
      WHERE sce.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)".($admin?'':" AND COALESCE(s.moderation_status,'visible')='visible'")."
      ORDER BY sce.id DESC LIMIT 12";
    $q=$pdo->prepare($sql);$q->execute([$viewer['id']]);
    foreach($q->fetchAll() as $row){
        if(!source_access($pdo,(string)$row['source_public_id'],$viewer))continue;
        $priority=((bool)$row['target_changed']||(int)$row['affected_annotation_count']>0)?'high':'medium';
        $title=(string)($row['title']?:$row['domain']?:'A watched source');
        $body=trim((string)($row['diff_summary']??''));if($body==='')$body='This watched source was '.str_replace('_',' ',(string)($row['impact_type']?:$row['change_type'])).'.';
        cognitive_feed_add($items,[
          'key'=>cognitive_feed_key('source_change','source',(string)$row['source_public_id'],(string)$row['created_at']),
          'type'=>'source_change','section'=>'source_changed','priority'=>$priority,'created_at'=>$row['created_at'],
          'score_extra'=>min(6,(int)$row['affected_annotation_count']),
          'title'=>'Source changed: '.$title,'body'=>$body,
          'meta'=>['affected_annotations'=>(int)$row['affected_annotation_count']],
          'actions'=>[
            cognitive_feed_action_link('Open source','/source.php?id='.rawurlencode((string)$row['source_public_id'])),
            cognitive_feed_action_agent('Ask Agent','Explain what changed in this source and what I should review next.',[['type'=>'source','public_id'=>(string)$row['source_public_id']]])
          ]
        ]);
    }
}

function cognitive_feed_collect_recent_agent_results(PDO $pdo,array $viewer,array &$items): void {
    if(!agent_actions_ready($pdo))return;
    $q=$pdo->prepare("SELECT aap.public_id,aap.capability_key,aap.executed_at,aap.result_json,rp.public_id project_public_id,rp.title project_title
      FROM agent_action_proposals aap JOIN research_projects rp ON rp.id=aap.project_id
      WHERE aap.proposed_by_user_id=? AND aap.status='executed' AND aap.executed_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)
      ORDER BY aap.executed_at DESC LIMIT 8");
    $q->execute([$viewer['id']]);$caps=agent_action_capabilities();
    foreach($q->fetchAll() as $row){
        $project=project_access($pdo,(int)$viewer['id'],(string)$row['project_public_id']);if(!$project)continue;
        $result=json_decode((string)($row['result_json']??''),true)?:[];$label=(string)($caps[$row['capability_key']]['label']??'Research action');
        $actions=[];if(!empty($result['url']))$actions[]=cognitive_feed_action_link('Open result',(string)$result['url']);$actions[]=cognitive_feed_action_link('Open Research','/research-project.php?id='.rawurlencode((string)$row['project_public_id']));
        cognitive_feed_add($items,[
          'key'=>cognitive_feed_key('recent_change','agent_action',(string)$row['public_id']),
          'type'=>'recent_change','section'=>'recent_changes','priority'=>'low','created_at'=>$row['executed_at'],
          'title'=>$label.' completed',
          'body'=>(string)($result['label']??$label).' was added to '.$row['project_title'].'.',
          'meta'=>['project'=>$row['project_title']],'actions'=>$actions
        ]);
    }
}


function cognitive_feed_collect_project_research(PDO $pdo,array $viewer,array $project,array &$items): void {
    if(!research_workspace_ready($pdo))return;$projectId=(int)$project['id'];$projectPublic=(string)$project['public_id'];$projectTitle=(string)$project['title'];$writable=cognitive_feed_project_writable($project);
    $context=[['type'=>'research','public_id'=>$projectPublic]];$snapshot=research_workspace_deterministic_snapshot($pdo,$projectId);$claimUpdated=[];
    foreach((array)($snapshot['claims']??[]) as $claimRow)if(!empty($claimRow['public_id']))$claimUpdated[(string)$claimRow['public_id']]=(string)($claimRow['updated_at']??$project['updated_at']??'');

    foreach(array_slice((array)($snapshot['gaps']??[]),0,5) as $gap){
        $claim=(string)($gap['claim_id']??'');$detail=(string)($gap['detail']??'');$priority=(string)($gap['priority']??'medium');
        $revision=hash('sha256',$detail.'|'.$priority);
        $actions=[cognitive_feed_action_link('Open Research','/research-project.php?id='.rawurlencode($projectPublic)),cognitive_feed_action_agent('Investigate','Investigate this Research gap: '.$detail.' Explain what evidence is missing and what should be checked next.',$context)];
        if($claim!=='')array_unshift($actions,cognitive_feed_action_link('Open claim','/research-claim.php?id='.rawurlencode($claim)));
        if($writable)$actions[]=cognitive_feed_action_agent('Propose task','Review this Research gap and propose a bounded follow-up task. Do not execute anything without my confirmation.',$context);
        cognitive_feed_add($items,[
          'key'=>cognitive_feed_key('research_gap','claim',$claim!==''?$claim:$projectPublic,$revision),
          'type'=>'research_gap','section'=>'needs_attention','priority'=>$priority,'created_at'=>$claimUpdated[$claim]??($project['updated_at']??null),
          'title'=>(string)($gap['title']??'Research gap in '.$projectTitle),
          'body'=>$detail,'meta'=>['project'=>$projectTitle],'actions'=>$actions
        ]);
    }

    foreach(array_slice((array)($snapshot['conflicts']??[]),0,5) as $conflict){
        $claim=(string)($conflict['claim_id']??'');$target=(string)($conflict['target_claim_id']??'');$detail=(string)($conflict['detail']??'');$priority=(string)($conflict['priority']??'high');
        $revision=hash('sha256',$detail.'|'.$target.'|'.$priority);
        $actions=[cognitive_feed_action_link('Open Research','/research-project.php?id='.rawurlencode($projectPublic)),cognitive_feed_action_agent('Compare evidence','Compare the conflicting evidence in this Research project and explain what would resolve the disagreement.',$context)];
        if($claim!=='')array_unshift($actions,cognitive_feed_action_link('Open claim','/research-claim.php?id='.rawurlencode($claim)));
        cognitive_feed_add($items,[
          'key'=>cognitive_feed_key('research_conflict','claim',$claim!==''?$claim:$projectPublic,$revision),
          'type'=>'research_conflict','section'=>'needs_attention','priority'=>$priority,'created_at'=>$claimUpdated[$claim]??($project['updated_at']??null),
          'title'=>(string)($conflict['title']??'Conflicting Research evidence'),
          'body'=>$detail,'meta'=>['project'=>$projectTitle],'actions'=>$actions
        ]);
    }

    foreach(array_slice((array)($snapshot['source_risks']??[]),0,5) as $risk){
        $source=(string)($risk['source_public_id']??'');if($source==='')continue;$changed=(string)($risk['last_change_at']??($project['updated_at']??''));
        $body=trim((string)($risk['latest_diff']??''));if($body==='')$body='This project source changed after evidence was captured.';
        cognitive_feed_add($items,[
          'key'=>cognitive_feed_key('source_change','source',$source,$changed),
          'type'=>'source_change','section'=>'source_changed','priority'=>!empty($risk['target_changed'])?'high':'medium','created_at'=>$changed,
          'score_extra'=>min(6,(int)($risk['affected_claims']??0)),
          'title'=>'Project source changed: '.(string)($risk['title']?:$risk['domain']?:'Source'),'body'=>$body,
          'meta'=>['project'=>$projectTitle,'affected_claims'=>(int)($risk['affected_claims']??0)],
          'actions'=>[
            cognitive_feed_action_link('Open source','/source.php?id='.rawurlencode($source)),
            cognitive_feed_action_link('Open Research','/research-project.php?id='.rawurlencode($projectPublic)),
            cognitive_feed_action_agent('Review impact','Review this changed source in the context of the Research project. Identify affected Claims or Findings and what should be re-verified.',$context)
          ]
        ]);
    }

    foreach(array_slice((array)($snapshot['next_actions']??[]),0,8) as $next){
        $type=(string)($next['type']??'');if(!in_array($type,['create_finding','continue_tasks'],true))continue;
        $section=$type==='create_finding'?'opportunities':'continue_researching';$obsType=$type==='create_finding'?'research_opportunity':'research_task';
        $reason=(string)($next['reason']??$next['detail']??'');$title=(string)($next['title']??($type==='create_finding'?'Synthesize a finding':'Continue Research'));
        $prompt=$type==='create_finding'?'Review the strongest supported Claims and propose a draft Finding if the evidence is sufficient. Do not create it without my confirmation.':'Review the open Research tasks and tell me the most useful one to continue next.';
        cognitive_feed_add($items,[
          'key'=>cognitive_feed_key($obsType,'project',$projectPublic,hash('sha256',$type.'|'.$reason)),
          'type'=>$obsType,'section'=>$section,'priority'=>(string)($next['priority']??'medium'),'created_at'=>$project['updated_at']??null,
          'title'=>$title,'body'=>$reason,'meta'=>['project'=>$projectTitle],
          'actions'=>[
            cognitive_feed_action_link('Open Research','/research-project.php?id='.rawurlencode($projectPublic)),
            cognitive_feed_action_agent($type==='create_finding'?'Draft finding':'Ask Agent',$prompt,$context)
          ]
        ]);
    }

    $q=$pdo->prepare("SELECT public_id,title,description,task_type,status,due_at,updated_at FROM research_tasks
      WHERE project_id=? AND status IN ('open','in_progress') AND (assigned_user_id IS NULL OR assigned_user_id=?)
      ORDER BY (status='in_progress') DESC,(due_at IS NULL),due_at,updated_at DESC LIMIT 4");
    $q->execute([$projectId,$viewer['id']]);
    foreach($q->fetchAll() as $task){
        $priority='medium';$extra=0;if(!empty($task['due_at'])&&strtotime((string)$task['due_at'])<time()){$priority='high';$extra=8;}elseif($task['status']==='in_progress')$extra=4;
        $body=trim((string)($task['description']??''));if($body==='')$body='This Research task is '.str_replace('_',' ',(string)$task['status']).'.';
        cognitive_feed_add($items,[
          'key'=>cognitive_feed_key('research_task','task',(string)$task['public_id'],(string)$task['updated_at']),
          'type'=>'research_task','section'=>'continue_researching','priority'=>$priority,'created_at'=>$task['updated_at'],'score_extra'=>$extra,
          'title'=>(string)$task['title'],'body'=>$body,'meta'=>['project'=>$projectTitle,'status'=>$task['status'],'due_at'=>$task['due_at']],
          'actions'=>[
            cognitive_feed_action_link('Open Research','/research-project.php?id='.rawurlencode($projectPublic).'#tasks'),
            cognitive_feed_action_agent('Ask Agent','Help me continue this Research task: '.(string)$task['title'].'. Recommend the next concrete step using the current project evidence.',$context)
          ]
        ]);
    }

    $q=$pdo->prepare("SELECT pa.created_at added_at,a.id annotation_id,a.public_id annotation_public_id,a.text_commentary,s.title source_title,s.domain,u.display_name
      FROM project_annotations pa JOIN annotations a ON a.id=pa.annotation_id JOIN sources s ON s.id=a.source_id JOIN users u ON u.id=a.user_id
      WHERE pa.project_id=? AND a.status='published' AND pa.created_at>=DATE_SUB(NOW(),INTERVAL 14 DAY)
      ORDER BY pa.created_at DESC LIMIT 5");
    $q->execute([$projectId]);
    foreach($q->fetchAll() as $evidence){
        $access=annotation_access($pdo,(string)$evidence['annotation_public_id'],$viewer);if(!$access)continue;
        $body=trim((string)($evidence['text_commentary']??''));if($body==='')$body='Evidence from '.(string)($evidence['source_title']?:$evidence['domain']?:'a source').' was added to this project.';
        $annotationContext=['type'=>'annotation','public_id'=>(string)$evidence['annotation_public_id']];
        cognitive_feed_add($items,[
          'key'=>cognitive_feed_key('new_evidence','project_annotation',$projectPublic.':'.$evidence['annotation_public_id'],(string)$evidence['added_at']),
          'type'=>'new_evidence','section'=>'new_evidence','priority'=>'medium','created_at'=>$evidence['added_at'],
          'title'=>'New evidence in '.$projectTitle,'body'=>mb_substr($body,0,500),
          'meta'=>['project'=>$projectTitle,'author'=>$evidence['display_name']],
          'actions'=>[
            cognitive_feed_action_link('Open annotation','/annotation.php?id='.rawurlencode((string)$evidence['annotation_public_id'])),
            cognitive_feed_action_link('Open Research','/research-project.php?id='.rawurlencode($projectPublic)),
            cognitive_feed_action_agent('Ask Agent','Explain how this annotation affects the current Research project and whether it changes any Claims or gaps.',[$context[0],$annotationContext])
          ]
        ]);
    }

    foreach(array_slice((array)($snapshot['annotation_links']??[]),0,10) as $link){
        $rel=(string)($link['relation_type']??'related');$source=(string)($link['source_annotation_id']??'');$target=(string)($link['target_annotation_id']??'');if($source===''||$target==='')continue;
        $isConflict=$rel==='conflicts';$section=$isConflict?'needs_attention':'related_research';$type=$isConflict?'related_conflict':'related_research';$priority=$isConflict?'high':'medium';
        cognitive_feed_add($items,[
          'key'=>cognitive_feed_key($type,'annotation_pair',$source.':'.$target,$rel),
          'type'=>$type,'section'=>$section,'priority'=>$priority,'created_at'=>$project['updated_at']??null,'score_extra'=>(int)round(((float)($link['confidence']??0))*4),
          'title'=>ucfirst($rel).' evidence in '.$projectTitle,
          'body'=>(string)($link['source_title']?:'Annotation').' ↔ '.(string)($link['target_title']?:'Annotation'),
          'meta'=>['project'=>$projectTitle,'analysis_confidence'=>(float)($link['confidence']??0)],
          'actions'=>[
            cognitive_feed_action_link('Open evidence','/annotation.php?id='.rawurlencode($source)),
            cognitive_feed_action_agent($isConflict?'Compare evidence':'Ask Agent',$isConflict?'Compare these related annotations and explain the conflict in the context of the Research project.':'Explain how these related annotations strengthen or change the Research project.',[$context[0],['type'=>'annotation','public_id'=>$source],['type'=>'annotation','public_id'=>$target]])
          ]
        ]);
    }

    $projectAnnotationIds=[];$q=$pdo->prepare("SELECT a.id,a.public_id FROM project_annotations pa JOIN annotations a ON a.id=pa.annotation_id WHERE pa.project_id=? AND a.status='published' ORDER BY pa.created_at DESC LIMIT 10");$q->execute([$projectId]);$recentProjectAnnotations=$q->fetchAll();
    foreach($recentProjectAnnotations as $x)$projectAnnotationIds[(string)$x['public_id']]=true;
    foreach(array_slice($recentProjectAnnotations,0,6) as $sourceAnnotation){
        foreach(annotation_intelligence_visible_relationships($pdo,(int)$sourceAnnotation['id'],$viewer,3) as $rel){
            $target=(string)($rel['public_id']??'');if($target===''||isset($projectAnnotationIds[$target]))continue;
            $relation=(string)($rel['relation_type']??'related');$conflict=$relation==='conflicts';
            cognitive_feed_add($items,[
              'key'=>cognitive_feed_key($conflict?'related_conflict':'related_research','external_annotation',$projectPublic.':'.$target,$relation),
              'type'=>$conflict?'related_conflict':'related_research','section'=>$conflict?'needs_attention':'related_research','priority'=>$conflict?'high':'medium','created_at'=>$project['updated_at']??null,
              'score_extra'=>(int)round(((float)($rel['confidence']??0))*5),
              'title'=>$conflict?'Conflicting evidence related to '.$projectTitle:'Related evidence outside '.$projectTitle,
              'body'=>mb_substr((string)($rel['text_commentary']?:$rel['selected_text']?:$rel['source_title']?:'A related annotation was detected.'),0,500),
              'meta'=>['project'=>$projectTitle,'relationship'=>$relation,'analysis_confidence'=>(float)($rel['confidence']??0)],
              'actions'=>[
                cognitive_feed_action_link('Open annotation','/annotation.php?id='.rawurlencode($target)),
                cognitive_feed_action_agent($conflict?'Compare':'Review relation',$conflict?'Compare this external evidence with the current Research project and explain the conflict.':'Review this related annotation and explain whether it should influence the current Research project.',[$context[0],['type'=>'annotation','public_id'=>$target]])
              ]
            ]);
        }
    }

    $activityCount=0;
    foreach((array)($snapshot['recent_activity']??[]) as $event){
        if($activityCount>=2)break;$when=(string)($event['occurred_at']??'');if($when===''||strtotime($when)<time()-7*86400)continue;
        $type=(string)($event['type']??'change');if(in_array($type,['source_change','task'],true))continue;
        cognitive_feed_add($items,[
          'key'=>cognitive_feed_key('recent_change','research_activity',$projectPublic.':'.(string)($event['href']??$event['title']??''),$when),
          'type'=>'recent_change','section'=>'recent_changes','priority'=>'low','created_at'=>$when,
          'title'=>(string)($event['title']??'Research updated'),'body'=>(string)($event['body']??''),'meta'=>['project'=>$projectTitle],
          'actions'=>array_values(array_filter([
            !empty($event['href'])?cognitive_feed_action_link('Open',(string)$event['href']):null,
            cognitive_feed_action_link('Open Research','/research-project.php?id='.rawurlencode($projectPublic))
          ]))
        ]);$activityCount++;
    }
}

function cognitive_feed_collect_research(PDO $pdo,array $viewer,array &$items): void {
    foreach(cognitive_feed_projects($pdo,$viewer,6) as $project)cognitive_feed_collect_project_research($pdo,$viewer,$project,$items);
    if(function_exists('cross_research_ready')&&cross_research_ready($pdo))cross_research_cognitive_observations($pdo,$viewer,$items,14);
    if(function_exists('research_reviews_ready')&&research_reviews_ready($pdo))research_review_cognitive_observations($pdo,$viewer,$items,24);
    if(function_exists('change_impact_ready')&&change_impact_ready($pdo))change_impact_cognitive_observations($pdo,$viewer,$items,12);
    if(function_exists('research_outcomes_ready')&&research_outcomes_ready($pdo))research_outcome_cognitive_observations($pdo,$viewer,$items,12);
    if(function_exists('research_network_ready')&&research_network_ready($pdo))research_network_cognitive_observations($pdo,$viewer,$items,12);
}


function cognitive_feed_compose(PDO $pdo,array $viewer,?array $teamList=null,int $perSection=4,int $maxTotal=28): array {
    $perSection=max(1,min(8,$perSection));$maxTotal=max(4,min(60,$maxTotal));
    if(!cognitive_feed_ready($pdo))return ['ready'=>false,'sections'=>[],'total'=>0,'hidden_count'=>0,'ranking'=>''];
    $items=[];
    cognitive_feed_collect_pending_actions($pdo,$viewer,$items);
    cognitive_feed_collect_research($pdo,$viewer,$items);
    cognitive_feed_collect_watched_source_changes($pdo,$viewer,$items);
    cognitive_feed_collect_team_activity($pdo,$viewer,$items,$teamList);
    cognitive_feed_collect_recent_agent_results($pdo,$viewer,$items);

    $dismissed=cognitive_feed_dismissed_keys($pdo,(int)$viewer['id']);$activeHidden=0;
    foreach(array_keys($items) as $key)if(isset($dismissed[$key])){$activeHidden++;unset($items[$key]);}

    $rows=array_values($items);
    usort($rows,function($a,$b){
        $score=((int)($b['score']??0))<=>((int)($a['score']??0));if($score!==0)return $score;
        return (strtotime((string)($b['created_at']??''))?:0)<=>(strtotime((string)($a['created_at']??''))?:0);
    });

    $defs=cognitive_feed_section_definitions();$buckets=[];$total=0;
    foreach($rows as $row){
        if($total>=$maxTotal)break;$section=(string)($row['section']??'recent_changes');if(!isset($defs[$section]))$section='recent_changes';
        if(count($buckets[$section]??[])>=$perSection)continue;$buckets[$section][]=$row;$total++;
    }
    $sections=[];foreach($defs as $key=>$def)if(!empty($buckets[$key]))$sections[]=['key'=>$key,'label'=>$def['label'],'description'=>$def['description'],'items'=>$buckets[$key]];
    return [
      'ready'=>true,
      'sections'=>$sections,
      'total'=>$total,
      'hidden_count'=>$activeHidden,
      'ranking'=>'Ranked from authoritative Annotated state using unresolved urgency, evidence impact, freshness, unread collaboration, and active Research context. No separate AI ranking model is required.'
    ];
}
