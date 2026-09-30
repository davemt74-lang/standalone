<?php
declare(strict_types=1);

/**
 * Phase 74 Section 4 — Universal Search + Command Palette.
 * Read-only adapter over canonical domain services. It does not create a second
 * index or object model; every result is revalidated through existing access.
 */
function universal_command_match(string $haystack,string $term): bool {
    $term=mb_strtolower(trim($term));if($term==='')return true;
    return str_contains(mb_strtolower($haystack),$term);
}
function universal_command_item(string $type,string $id,string $title,string $url,string $meta='',string $snippet=''): array {
    return ['type'=>$type,'public_id'=>$id,'title'=>$title,'url'=>$url,'meta'=>$meta,'snippet'=>$snippet];
}
function universal_command_create_actions(): array {
    return [
      ['action'=>'research_agent','title'=>'Create Research Agent','meta'=>'Create','icon'=>'✦'],
      ['action'=>'portfolio','title'=>'Create Portfolio','meta'=>'Create','icon'=>'▦'],
      ['action'=>'mission','title'=>'Create Mission','meta'=>'Create','icon'=>'◎'],
      ['action'=>'task','title'=>'Create Task','meta'=>'Create','icon'=>'✓'],
      ['action'=>'program','title'=>'Create Program','meta'=>'Create','icon'=>'↻'],
      ['action'=>'decision','title'=>'Create Decision','meta'=>'Create','icon'=>'◇'],
      ['action'=>'action_plan','title'=>'Create Action Plan','meta'=>'Create','icon'=>'→'],
      ['action'=>'document','title'=>'Create Document','meta'=>'Create','icon'=>'▤'],
      ['action'=>'report','title'=>'Generate Report','meta'=>'Create','icon'=>'≡'],
      ['action'=>'sticky','title'=>'Create Sticky Note','meta'=>'Create','icon'=>'□'],
      ['action'=>'team','title'=>'Create Team','meta'=>'Create','icon'=>'♙'],
      ['action'=>'source','title'=>'Add Source','meta'=>'Create','icon'=>'⌁'],
    ];
}
function universal_command_shortcuts(PDO $pdo,array $viewer,string $term='',int $limit=12): array {
    if(!function_exists('research_object_shortcuts'))return [];$out=[];
    foreach(research_object_shortcuts($pdo,$viewer,max($limit,20)) as $s){
        if($term!==''&&!universal_command_match((string)$s['title'].' '.str_replace('_',' ',(string)$s['object_type']),$term))continue;
        $d=research_object_descriptor($pdo,$viewer,['type'=>(string)$s['object_type'],'public_id'=>(string)$s['object_public_id']]);
        if(!$d)continue;
        $out[]=universal_command_item((string)$d['type'],(string)$d['public_id'],(string)$d['title'],(string)$d['url'],!empty($s['pinned_at'])?'Pinned · '.$d['type_label']:'Recent · '.$d['type_label']);
        if(count($out)>=$limit)break;
    }
    return $out;
}
function universal_command_search(PDO $pdo,array $viewer,string $raw,int $limitPerGroup=8): array {
    $term=search_normalize_query($raw);$limitPerGroup=max(3,min(15,$limitPerGroup));
    $groups=['shortcuts'=>universal_command_shortcuts($pdo,$viewer,$term,$limitPerGroup)];
    $actions=[];foreach(universal_command_create_actions() as $a)if($term===''||universal_command_match($a['title'].' '.$a['meta'],$term))$actions[]=$a;
    $groups['actions']=array_slice($actions,0,$limitPerGroup);
    if(mb_strlen($term)<2)return ['query'=>$term,'groups'=>$groups,'total'=>array_sum(array_map('count',$groups))];

    $agents=[];$agentRows=function_exists('research_agent_list')?research_agent_list($pdo,$viewer,50):[];
    foreach($agentRows as $a){
        if(!universal_command_match((string)$a['name'].' '.(string)$a['description'].' '.(string)$a['team_name'],$term))continue;
        $agents[]=universal_command_item('research_agent',(string)$a['public_id'],(string)$a['name'],'/home.php?agent='.rawurlencode((string)$a['conversation_public_id']),'Research Agent'.(!empty($a['team_name'])?' · '.$a['team_name']:''));
        if(count($agents)>=$limitPerGroup)break;
    }
    $groups['research_agents']=$agents;

    $portfolios=[];
    if(function_exists('research_intelligence_portfolio_list'))foreach(research_intelligence_portfolio_list($pdo,$viewer,100,false) as $p){
        if(!universal_command_match((string)$p['title'].' '.(string)$p['objective'].' '.(string)($p['team_name']??''),$term))continue;
        $portfolios[]=universal_command_item('portfolio',(string)$p['public_id'],(string)$p['title'],'/research-intelligence-portfolios.php?portfolio='.rawurlencode((string)$p['public_id']),'Portfolio'.(!empty($p['team_name'])?' · '.$p['team_name']:''));
        if(count($portfolios)>=$limitPerGroup)break;
    }
    $groups['portfolios']=$portfolios;

    $missions=[];$programs=[];
    foreach($agentRows as $a){
        $agentId=(string)$a['public_id'];
        if(function_exists('research_mission_list')){try{foreach(research_mission_list($pdo,$viewer,$agentId,100) as $m){
            if(!universal_command_match((string)$m['title'].' '.(string)$m['objective'].' '.(string)$m['research_question'],$term))continue;
            $missions[]=universal_command_item('mission',(string)$m['public_id'],(string)$m['title'],'/research-missions.php?agent='.rawurlencode($agentId).'&mission='.rawurlencode((string)$m['public_id']),'Mission · '.(string)$a['name']);
            if(count($missions)>=$limitPerGroup)break 2;
        }}catch(Throwable $e){}}
    }
    foreach($agentRows as $a){
        $agentId=(string)$a['public_id'];
        if(function_exists('research_program_list')){try{foreach(research_program_list($pdo,$viewer,$agentId,100) as $p){
            if(!universal_command_match((string)$p['title'].' '.(string)$p['objective'],$term))continue;
            $programs[]=universal_command_item('program',(string)$p['public_id'],(string)$p['title'],'/research-programs.php?agent='.rawurlencode($agentId).'&program='.rawurlencode((string)$p['public_id']),'Program · '.(string)$a['name']);
            if(count($programs)>=$limitPerGroup)break 2;
        }}catch(Throwable $e){}}
    }
    $groups['missions']=$missions;$groups['programs']=$programs;

    $tasks=[];
    try{
        $like='%'.$term.'%';$uid=(int)$viewer['id'];
        $q=$pdo->prepare("SELECT rt.public_id,rt.title,rt.description,rt.status,ra.public_id agent_public_id,ra.name agent_name
          FROM research_tasks rt JOIN research_projects rp ON rp.id=rt.project_id
          LEFT JOIN research_agents ra ON ra.id=rt.research_agent_id
          LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=?
          WHERE rt.status<>'archived' AND ((rp.team_id IS NULL AND rp.owner_user_id=?) OR (rp.team_id IS NOT NULL AND tm.user_id=?))
            AND (rt.title LIKE ? OR rt.description LIKE ?)
          ORDER BY rt.updated_at DESC LIMIT ".$limitPerGroup);
        $q->execute([$uid,$uid,$uid,$like,$like]);
        foreach($q->fetchAll() as $t)$tasks[]=universal_command_item('task',(string)$t['public_id'],(string)$t['title'],'/research-tasks.php?agent='.rawurlencode((string)$t['agent_public_id']).'&task='.rawurlencode((string)$t['public_id']),'Task · '.((string)($t['agent_name']??'Research')));
    }catch(Throwable $e){}
    $groups['tasks']=$tasks;

    $teams=[];$conversations=[];
    if(function_exists('conversation_team_list')){try{foreach(conversation_team_list($pdo,$viewer) as $c){
        if(!universal_command_match((string)$c['team_name'].' '.(string)$c['last_message'],$term))continue;
        $teams[]=universal_command_item('team',(string)$c['team_public_id'],(string)$c['team_name'],'/team.php?id='.rawurlencode((string)$c['team_public_id']),'Team · '.(int)$c['member_count'].' members');
        $conversations[]=universal_command_item('conversation',(string)$c['public_id'],(string)$c['team_name'].' chat','/home.php?team_chat='.rawurlencode((string)$c['public_id']),'Team Chat',(string)$c['last_message']);
        if(count($teams)>=$limitPerGroup)break;
    }}catch(Throwable $e){}}
    $groups['teams']=$teams;$groups['conversations']=$conversations;

    $canonical=search_unified($pdo,$term,$viewer,[],false);
    $map=[
      'projects'=>['project','Research','/research-project.php?id='],
      'reports'=>['report','Report','/research-report.php?id='],
      'people'=>['person','Person','/profile.php?u='],
      'sources'=>['source','Source','/source.php?id='],
      'annotations'=>['annotation','Annotation','/annotation.php?id='],
      'entities'=>['entity','Entity','/entity.php?id='],
      'research'=>['research','Research','/research.php?q=']
    ];
    foreach($map as $key=>[$type,$label,$prefix]){
        $items=[];foreach(array_slice($canonical[$key]??[],0,$limitPerGroup) as $row){
            $id=(string)($row['public_id']??$row['username']??'');
            $title=(string)($row['title']??$row['display_name']??$row['canonical_name']??$row['statement']??$row['snippet']??'Untitled');
            if($key==='annotations')$title=(string)($row['source_title']??'Annotation');
            $url=$key==='people'?'/'.rawurlencode((string)($row['username']??'')):$prefix.rawurlencode($id);
            if($key==='research')$url='/research.php?q='.rawurlencode($term);
            $items[]=universal_command_item($type,$id,$title,$url,$label,(string)($row['snippet']??$row['description']??$row['text_commentary']??''));
        }$groups[$key]=$items;
    }
    $total=array_sum(array_map('count',$groups));
    return ['query'=>$term,'groups'=>$groups,'total'=>$total];
}
function universal_command_agent_resolve(PDO $pdo,array $viewer,string $request): array {
    return universal_command_search($pdo,$viewer,$request,5);
}
