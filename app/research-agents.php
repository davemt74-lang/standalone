<?php
declare(strict_types=1);

require_once __DIR__.'/agent-chat.php';
require_once __DIR__.'/research-automation.php';

function research_agent_ready(PDO $pdo): bool {
    try{
        return installer_table_exists($pdo,'research_agents')
            &&installer_table_exists($pdo,'research_projects')
            &&installer_table_exists($pdo,'conversations')
            &&installer_table_exists($pdo,'conversation_members');
    }catch(Throwable $e){
        return false;
    }
}

function research_agent_social_visibility_sql(bool $requirePublicFollow=false): string {
    $public=$requirePublicFollow
      ? "(ra.visibility='public' AND EXISTS(SELECT 1 FROM follows raf WHERE raf.follower_user_id=:social_follow AND raf.followed_user_id=ra.owner_user_id))"
      : "ra.visibility='public'";
    return "(
      ra.owner_user_id=:social_owner
      OR EXISTS(SELECT 1 FROM team_members ratm WHERE ratm.team_id=ra.team_id AND ratm.user_id=:social_team)
      OR (
        (
          ".$public."
          OR (
            ra.visibility='friends'
            AND EXISTS(SELECT 1 FROM follows raf1 WHERE raf1.follower_user_id=:social_friend1 AND raf1.followed_user_id=ra.owner_user_id)
            AND EXISTS(SELECT 1 FROM follows raf2 WHERE raf2.follower_user_id=ra.owner_user_id AND raf2.followed_user_id=:social_friend2)
          )
        )
        AND NOT EXISTS(
          SELECT 1 FROM blocks rab
          WHERE (rab.blocker_user_id=:social_block1 AND rab.blocked_user_id=ra.owner_user_id)
             OR (rab.blocker_user_id=ra.owner_user_id AND rab.blocked_user_id=:social_block2)
        )
      )
    )";
}
function research_agent_social_visibility_params(array $viewer): array {
    $id=(int)($viewer['id']??0);
    return [
      ':social_owner'=>$id,':social_team'=>$id,':social_follow'=>$id,
      ':social_friend1'=>$id,':social_friend2'=>$id,':social_block1'=>$id,':social_block2'=>$id
    ];
}
function research_agent_social_access(PDO $pdo,array $viewer,string $publicId): ?array {
    $publicId=trim($publicId);if($publicId===''||!research_agent_ready($pdo))return null;
    $sql="SELECT ra.public_id,ra.name,ra.description,ra.profile_image_url,ra.visibility,ra.status,ra.updated_at,
      ra.owner_user_id,rp.public_id project_public_id,c.public_id conversation_public_id,
      u.username owner_username,u.display_name owner_display_name,t.public_id team_public_id,t.name team_name
      FROM research_agents ra
      JOIN research_projects rp ON rp.id=ra.project_id
      JOIN conversations c ON c.id=ra.conversation_id
      JOIN users u ON u.id=ra.owner_user_id AND u.status='active'
      LEFT JOIN teams t ON t.id=ra.team_id
      WHERE ra.public_id=:agent_public AND ra.status<>'archived' AND ".research_agent_social_visibility_sql(false)." LIMIT 1";
    $q=$pdo->prepare($sql);$params=research_agent_social_visibility_params($viewer);$params[':agent_public']=$publicId;$q->execute($params);
    return $q->fetch()?:null;
}
function research_agent_profile_list(PDO $pdo,int $ownerUserId,?array $viewer,int $limit=30): array {
    if(!research_agent_ready($pdo)||$ownerUserId<1)return [];
    $limit=max(1,min(60,$limit));$viewerId=(int)($viewer['id']??0);
    if($viewerId<1){
        $q=$pdo->prepare("SELECT ra.public_id,ra.name,ra.description,ra.profile_image_url,ra.visibility,ra.updated_at
          FROM research_agents ra JOIN users u ON u.id=ra.owner_user_id AND u.status='active'
          WHERE ra.owner_user_id=? AND ra.status<>'archived' AND ra.visibility='public'
          ORDER BY ra.updated_at DESC,ra.id DESC LIMIT ".$limit);
        $q->execute([$ownerUserId]);return $q->fetchAll()?:[];
    }
    $sql="SELECT ra.public_id,ra.name,ra.description,ra.profile_image_url,ra.visibility,ra.updated_at
      FROM research_agents ra
      WHERE ra.owner_user_id=:profile_owner AND ra.status<>'archived' AND ".research_agent_social_visibility_sql(false)."
      ORDER BY ra.updated_at DESC,ra.id DESC LIMIT ".$limit;
    $q=$pdo->prepare($sql);$params=research_agent_social_visibility_params($viewer);$params[':profile_owner']=$ownerUserId;$q->execute($params);
    $rows=$q->fetchAll()?:[];
    if(function_exists('research_agent_story_list')){
        $stories=research_agent_story_list($pdo,$viewer,60);$storyMeta=[];
        foreach($stories as $story){$agent=(string)($story['agent_public_id']??'');if($agent==='')continue;if(!isset($storyMeta[$agent]))$storyMeta[$agent]=['story_count'=>0,'latest_story_public_id'=>(string)$story['public_id'],'latest_story_url'=>(string)($story['story_url']??'')];$storyMeta[$agent]['story_count']++;}
        foreach($rows as &$row){$meta=$storyMeta[(string)$row['public_id']]??['story_count'=>0,'latest_story_public_id'=>null,'latest_story_url'=>null];$row=array_merge($row,$meta);}unset($row);
    }
    return $rows;
}


function research_agent_discover_public(PDO $pdo,?array $viewer,string $term='',int $limit=24): array {
    if(!research_agent_ready($pdo))return [];
    $limit=max(1,min(60,$limit));$term=trim($term);$viewerId=(int)($viewer['id']??0);
    $where=["ra.status<>'archived'","ra.visibility='public'","u.status='active'","COALESCE(up.profile_visibility,'public')='public'","COALESCE(up.search_visibility,1)=1"];
    $params=[];
    if($viewerId>0){
        $where[]='u.id<>?';$params[]=$viewerId;
        $where[]="NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.blocker_user_id=$viewerId AND b.blocked_user_id=u.id) OR (b.blocker_user_id=u.id AND b.blocked_user_id=$viewerId))";
    }
    if($term!==''){
        $where[]='(ra.name LIKE ? OR ra.description LIKE ? OR u.display_name LIKE ? OR u.username LIKE ?)';
        $like='%'.$term.'%';array_push($params,$like,$like,$like,$like);
    }
    $storyCount=(function_exists('research_agent_stories_ready')&&research_agent_stories_ready($pdo))
      ? "(SELECT COUNT(*) FROM research_agent_stories ras WHERE ras.agent_id=ra.id AND (ras.expires_at IS NULL OR ras.expires_at>NOW())) published_story_count"
      : "0 published_story_count";
    $sql="SELECT ra.public_id,ra.name,ra.description,ra.profile_image_url,ra.updated_at,
      ra.owner_user_id,u.public_id owner_public_id,u.username owner_username,u.display_name owner_display_name,u.profile_image_url owner_profile_image_url,
      ".$storyCount."
      FROM research_agents ra
      JOIN users u ON u.id=ra.owner_user_id
      LEFT JOIN user_preferences up ON up.user_id=u.id
      WHERE ".implode(' AND ',$where)."
      ORDER BY published_story_count DESC,ra.updated_at DESC,ra.id DESC LIMIT ".$limit;
    $q=$pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll()?:[];
    $storyMeta=[];
    if($viewer&&function_exists('research_agent_story_list')){
        foreach(research_agent_story_list($pdo,$viewer,80) as $story){
            $agent=(string)($story['agent_public_id']??'');if($agent==='')continue;
            if(!isset($storyMeta[$agent]))$storyMeta[$agent]=['accessible_story_count'=>0,'latest_story_url'=>(string)($story['story_url']??'')];
            $storyMeta[$agent]['accessible_story_count']++;
        }
    }
    foreach($rows as &$row){
        $meta=$storyMeta[(string)$row['public_id']]??['accessible_story_count'=>0,'latest_story_url'=>null];
        $row=array_merge($row,$meta);
        if($viewer&&function_exists('profile_network_relationship')){
            $ownerId=(int)($row['owner_user_id']??0);
            $row['owner_relationship']=$ownerId?profile_network_relationship($pdo,$viewer,$ownerId):['following'=>false,'follows_you'=>false,'friends'=>false,'blocked'=>false];
        }else $row['owner_relationship']=['following'=>false,'follows_you'=>false,'friends'=>false,'blocked'=>false];
    }
    unset($row);return $rows;
}

function research_agent_list(PDO $pdo,array $viewer,int $limit=30): array {
    if(!research_agent_ready($pdo))return [];
    $limit=max(1,min(50,$limit));
    $q=$pdo->prepare("SELECT ra.public_id,ra.name,ra.description,ra.profile_image_url,ra.visibility,ra.status,ra.monitoring_cadence,ra.is_default,ra.updated_at,
      rp.public_id project_public_id,rp.title project_title,
      c.public_id conversation_public_id,COALESCE(c.last_message_at,c.updated_at,c.created_at) activity_at,
      (SELECT m.body FROM conversation_messages m WHERE m.conversation_id=c.id AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 1) last_message,
      t.public_id team_public_id,t.name team_name
      FROM research_agents ra
      JOIN research_projects rp ON rp.id=ra.project_id AND rp.status<>'archived'
      JOIN conversations c ON c.id=ra.conversation_id AND c.conversation_type='agent'
      LEFT JOIN teams t ON t.id=ra.team_id
      LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=?
      WHERE ra.status<>'archived' AND ((ra.team_id IS NULL AND ra.owner_user_id=?) OR (ra.team_id IS NOT NULL AND tm.user_id=?))
      ORDER BY COALESCE(c.last_message_at,ra.updated_at,ra.created_at) DESC,ra.id DESC
      LIMIT ".$limit);
    $q->execute([(int)$viewer['id'],(int)$viewer['id'],(int)$viewer['id']]);
    $rows=$q->fetchAll()?:[];
    foreach($rows as &$row)$row['last_message']=mb_substr(trim((string)($row['last_message']??'')),0,120);
    unset($row);
    return $rows;
}


function research_agent_edit_context(PDO $pdo,array $viewer,string $publicId): array {
    $agent=research_agent_access($pdo,$viewer,$publicId);if(!$agent)throw new RuntimeException('Research Agent not found.');
    $canEdit=(int)$agent['owner_user_id']===(int)$viewer['id']||in_array((string)($agent['team_role']??''),['owner','admin'],true);
    if(!$canEdit)throw new RuntimeException('You do not have permission to edit this Research Agent.');
    $project=['public_id'=>(string)$agent['project_public_id'],'title'=>(string)$agent['project_title'],'description'=>(string)($agent['description']??''),'status'=>'active'];
    $q=$pdo->prepare('SELECT public_id,title,description,status,updated_at FROM research_projects WHERE id=? LIMIT 1');$q->execute([(int)$agent['project_id']]);$project=$q->fetch()?:$project;
    $automation=null;
    if(!empty($agent['automation_id'])&&function_exists('research_automation_ready')&&research_automation_ready($pdo)){
        $q=$pdo->prepare('SELECT public_id FROM research_automations WHERE id=? LIMIT 1');$q->execute([(int)$agent['automation_id']]);$ap=(string)($q->fetchColumn()?:'');
        if($ap!==''&&function_exists('research_automation_access'))$automation=research_automation_access($pdo,$viewer,$ap);
    }
    $missions=function_exists('research_missions_ready')&&research_missions_ready($pdo)?research_mission_list($pdo,$viewer,$publicId,100):[];
    $plans=function_exists('research_tasks_ready')&&research_tasks_ready($pdo)?research_task_plan_list($pdo,$viewer,$publicId,100):[];
    $programs=function_exists('research_programs_ready')&&research_programs_ready($pdo)?research_program_list($pdo,$viewer,$publicId,100):[];
    $watches=function_exists('research_monitor_ready')&&research_monitor_ready($pdo)?research_monitor_list($pdo,$viewer,$publicId,100):[];
    $portfolios=[];
    if(function_exists('research_intelligence_portfolios_ready')&&research_intelligence_portfolios_ready($pdo)){
        foreach(research_intelligence_portfolio_list($pdo,$viewer,120,false) as $portfolio){
            if(research_intelligence_portfolio_contains_project($pdo,$viewer,(string)$portfolio['public_id'],(int)$agent['project_id']))$portfolios[]=$portfolio;
        }
    }
    return ['agent'=>$agent,'project'=>$project,'automation'=>$automation,'missions'=>$missions,'plans'=>$plans,'programs'=>$programs,'watches'=>$watches,'portfolios'=>$portfolios];
}

function research_agent_update_settings(PDO $pdo,array $viewer,string $publicId,array $input): array {
    $agent=research_agent_update_profile($pdo,$viewer,$publicId,$input);
    $status=strtolower(trim((string)($input['status']??$agent['status']??'active')));if(!in_array($status,['active','paused'],true))$status='active';
    $cadence=strtolower(trim((string)($input['cadence']??$agent['monitoring_cadence']??'daily')));if(!in_array($cadence,['hourly','daily','weekly','manual'],true))$cadence='daily';
    $pdo->prepare('UPDATE research_agents SET status=?,monitoring_cadence=?,updated_at=NOW() WHERE id=?')->execute([$status,$cadence,(int)$agent['id']]);
    if(!empty($agent['automation_id'])&&function_exists('research_automation_ready')&&research_automation_ready($pdo)){
        $q=$pdo->prepare('SELECT public_id FROM research_automations WHERE id=? LIMIT 1');$q->execute([(int)$agent['automation_id']]);$automationPublic=(string)($q->fetchColumn()?:'');
        if($automationPublic!==''){
            $current=research_automation_access($pdo,$viewer,$automationPublic);
            if($current){
                $automationInput=[
                  'title'=>(string)($input['automation_title']??$current['title']),
                  'workflow_type'=>(string)$current['workflow_type'],
                  'trigger_type'=>(string)$current['trigger_type'],
                  'project_id'=>(string)$current['project_public_id'],
                  'cadence'=>$cadence,
                  'timezone_name'=>(string)($input['timezone_name']??$current['timezone_name']),
                  'run_time_local'=>(string)($input['run_time_local']??$current['run_time_local']),
                  'weekday'=>(int)($input['weekday']??$current['weekday']??1),
                  'prompt'=>(string)($input['automation_prompt']??$current['prompt']??'')
                ];
                research_automation_update($pdo,$viewer,$automationPublic,$automationInput);
                research_automation_set_status($pdo,$viewer,$automationPublic,$status==='active'?'active':'paused');
            }
        }
    }
    return research_agent_edit_context($pdo,$viewer,$publicId);
}

function research_agent_chat_feed(PDO $pdo,array $viewer,string $agentPublicId,int $limit=6): array {
    $agent=research_agent_access($pdo,$viewer,$agentPublicId);if(!$agent)return [];
    $limit=max(1,min(20,$limit));
    $q=$pdo->prepare("SELECT m.public_id,m.sender_type,m.body,m.created_at,u.username,u.display_name
      FROM conversation_messages m
      LEFT JOIN users u ON u.id=m.user_id
      WHERE m.conversation_id=? AND m.deleted_at IS NULL
      ORDER BY m.id DESC LIMIT ".$limit);
    $q->execute([(int)$agent['conversation_id']]);$rows=array_reverse($q->fetchAll()?:[]);
    foreach($rows as &$row){
        $row['body']=mb_substr(trim((string)$row['body']),0,420);
        $row['speaker']=($row['sender_type']??'')==='agent'?(string)$agent['name']:(string)($row['display_name']?:$row['username']?:'You');
    }
    unset($row);return $rows;
}

function research_agent_access(PDO $pdo,array $viewer,string $publicId): ?array {
    $publicId=trim($publicId);if($publicId===''||!research_agent_ready($pdo))return null;
    $q=$pdo->prepare("SELECT ra.*,rp.public_id project_public_id,rp.title project_title,c.public_id conversation_public_id,
      t.public_id team_public_id,t.name team_name,tm.role team_role
      FROM research_agents ra
      JOIN research_projects rp ON rp.id=ra.project_id
      JOIN conversations c ON c.id=ra.conversation_id
      LEFT JOIN teams t ON t.id=ra.team_id
      LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=?
      WHERE ra.public_id=? AND ((ra.team_id IS NULL AND ra.owner_user_id=?) OR (ra.team_id IS NOT NULL AND tm.user_id=?)) LIMIT 1");
    $q->execute([(int)$viewer['id'],$publicId,(int)$viewer['id'],(int)$viewer['id']]);
    $row=$q->fetch()?:null;if(!$row)return null;
    if(function_exists('sponsored_project_agent_context'))$row['sponsored_project_context']=sponsored_project_agent_context($pdo,(int)$row['id']);
    return $row;
}

function research_agent_by_conversation(PDO $pdo,array $viewer,string $conversationPublicId): ?array {
    if(!research_agent_ready($pdo))return null;
    $conversationPublicId=trim($conversationPublicId);if($conversationPublicId==='')return null;
    $q=$pdo->prepare("SELECT ra.public_id FROM research_agents ra
      JOIN conversations c ON c.id=ra.conversation_id
      LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=?
      WHERE c.public_id=? AND ra.status<>'archived' AND ((ra.team_id IS NULL AND ra.owner_user_id=?) OR (ra.team_id IS NOT NULL AND tm.user_id=?)) LIMIT 1");
    $q->execute([(int)$viewer['id'],$conversationPublicId,(int)$viewer['id'],(int)$viewer['id']]);
    $public=(string)($q->fetchColumn()?:'');
    return $public!==''?research_agent_access($pdo,$viewer,$public):null;
}

function research_agent_attach_context(PDO $pdo,array $viewer,array $agent,array $context): void {
    $projectId=(int)($agent['project_id']??0);if($projectId<=0)return;
    foreach($context as $item){
        if((string)($item['type']??'')!=='annotation')continue;
        $public=trim((string)($item['public_id']??''));if($public==='')continue;
        $q=$pdo->prepare("SELECT id FROM annotations WHERE public_id=? LIMIT 1");$q->execute([$public]);
        $annotationId=(int)($q->fetchColumn()?:0);if($annotationId<=0)continue;
        $pdo->prepare("INSERT IGNORE INTO project_annotations(project_id,annotation_id,added_by_user_id) VALUES(?,?,?)")
            ->execute([$projectId,$annotationId,(int)$viewer['id']]);
    }
}

function research_agent_team(PDO $pdo,array $viewer,string $teamPublicId): ?array {
    $teamPublicId=trim($teamPublicId);if($teamPublicId==='')return null;
    $q=$pdo->prepare("SELECT t.id,t.public_id,t.name,tm.role FROM teams t
      JOIN team_members tm ON tm.team_id=t.id AND tm.user_id=?
      WHERE t.public_id=? AND t.owner_user_id=? AND tm.role='owner' LIMIT 1");
    $q->execute([(int)$viewer['id'],$teamPublicId,(int)$viewer['id']]);
    return $q->fetch()?:null;
}

function research_agent_create(PDO $pdo,array $viewer,array $input,bool $isDefault=false): array {
    if(!research_agent_ready($pdo))throw new RuntimeException('Research Agents require the latest database upgrade.');
    if(!agent_chat_available($pdo,$viewer))throw new RuntimeException('Research Agents require Agent access.');

    $name=mb_substr(trim((string)($input['name']??'')),0,190);
    if($name==='')throw new InvalidArgumentException('Research Agent name is required.');
    $description=mb_substr(trim((string)($input['description']??'')),0,4000);
    $cadence=strtolower(trim((string)($input['cadence']??'daily')));
    if(!in_array($cadence,['hourly','daily','weekly','manual'],true))$cadence='daily';
    $timezone=trim((string)($input['timezone_name']??'UTC'));research_automation_timezone($timezone);
    $runTime=research_automation_time((string)($input['run_time_local']??'09:00'));
    $weekday=max(0,min(6,(int)($input['weekday']??1)));
    $team=research_agent_team($pdo,$viewer,(string)($input['team_id']??''));
    if(trim((string)($input['team_id']??''))!==''&&!$team)throw new RuntimeException('You do not have permission to create a Research Agent in that Team.');
    if($cadence!=='manual'&&!research_automation_ready($pdo))throw new RuntimeException('Research monitoring requires the latest database upgrade.');
    if($cadence!=='manual'&&research_automation_active_count($pdo,(int)$viewer['id'])>=25)throw new RuntimeException('You can keep up to 25 active or paused Research Automations.');

    $projectPublic=ulid_like();$conversationPublic=ulid_like();$agentPublic=ulid_like();$automationPublic=$cadence!=='manual'?ulid_like():null;
    $projectId=0;$conversationId=0;$automationId=null;
    $pdo->beginTransaction();
    try{
        $pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,team_id,title,description,status) VALUES(?,?,?,?,?,'active')")
            ->execute([$projectPublic,(int)$viewer['id'],$team['id']??null,$name,$description!==''?$description:null]);
        $projectId=(int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO conversations(public_id,conversation_type,created_by_user_id,title) VALUES(?,'agent',?,?)")
            ->execute([$conversationPublic,(int)$viewer['id'],$name]);
        $conversationId=(int)$pdo->lastInsertId();

        if($team){
            $pdo->prepare("INSERT INTO conversation_members(conversation_id,user_id,member_role)
              SELECT ?,tm.user_id,CASE WHEN tm.role IN ('owner','admin') THEN tm.role ELSE 'member' END
              FROM team_members tm WHERE tm.team_id=?")
              ->execute([$conversationId,(int)$team['id']]);
        }else{
            $pdo->prepare("INSERT INTO conversation_members(conversation_id,user_id,member_role) VALUES(?,?,'owner')")
              ->execute([$conversationId,(int)$viewer['id']]);
        }

        if($cadence!=='manual'){
            $next=research_automation_next_run($cadence,$timezone,$runTime,$cadence==='weekly'?$weekday:null);
            $prompt=$description!==''?
              'Continuously research this project with the following objective: '.$description.' Review meaningful changes, evidence gaps, conflicting sources, source risks, and useful next actions.':
              'Continuously research this project. Review meaningful changes, evidence gaps, conflicting sources, source risks, and useful next actions.';
            $pdo->prepare("INSERT INTO research_automations(public_id,user_id,project_id,conversation_id,title,workflow_type,trigger_type,cadence,timezone_name,run_time_local,weekday,prompt,status,next_run_at)
              VALUES(?,?,?,?,?,'review','schedule',?,?,?,?,?,'active',?)")
              ->execute([$automationPublic,(int)$viewer['id'],$projectId,$conversationId,$name.' Monitoring',$cadence,$timezone,$runTime,$cadence==='weekly'?$weekday:null,$prompt,$next]);
            $automationId=(int)$pdo->lastInsertId();
        }

        $profileImage=mb_substr(trim((string)($input['profile_image_url']??'')),0,500);if($profileImage!==''&&!preg_match('#^(https?://|/uploads/)#i',$profileImage))$profileImage='';
        $visibility=strtolower(trim((string)($input['visibility']??'private')));if(!in_array($visibility,['private','friends','public'],true))$visibility='private';
        $pdo->prepare("INSERT INTO research_agents(public_id,owner_user_id,team_id,project_id,conversation_id,automation_id,name,description,profile_image_url,visibility,status,monitoring_cadence,is_default)
          VALUES(?,?,?,?,?,?,?,?,?,?, 'active',?,?)")
          ->execute([$agentPublic,(int)$viewer['id'],$team['id']??null,$projectId,$conversationId,$automationId,$name,$description!==''?$description:null,$profileImage!==''?$profileImage:null,$visibility,$cadence,$isDefault?1:null]);

        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
    return research_agent_access($pdo,$viewer,$agentPublic)??[
      'public_id'=>$agentPublic,'name'=>$name,'project_public_id'=>$projectPublic,'conversation_public_id'=>$conversationPublic,'monitoring_cadence'=>$cadence
    ];
}

function research_agent_default(PDO $pdo,array $viewer): ?array {
    if(!research_agent_ready($pdo))return null;
    $q=$pdo->prepare("SELECT public_id FROM research_agents WHERE owner_user_id=? AND is_default=1 ORDER BY id ASC LIMIT 1");
    $q->execute([(int)$viewer['id']]);
    $public=(string)($q->fetchColumn()?:'');
    return $public!==''?research_agent_access($pdo,$viewer,$public):null;
}

function research_agent_activate_default(PDO $pdo,array $viewer,array $agent): array {
    if(($agent['status']??'active')!=='active'){
        $pdo->prepare("UPDATE research_agents SET status='active',updated_at=NOW() WHERE id=? AND owner_user_id=?")
            ->execute([(int)$agent['id'],(int)$viewer['id']]);
        $pdo->prepare("UPDATE research_projects SET status='active',updated_at=NOW() WHERE id=?")
            ->execute([(int)$agent['project_id']]);
        if(!empty($agent['automation_id'])){
            $pdo->prepare("UPDATE research_automations SET status='active',updated_at=NOW() WHERE id=? AND user_id=?")
                ->execute([(int)$agent['automation_id'],(int)$viewer['id']]);
        }
        $agent=research_agent_access($pdo,$viewer,(string)$agent['public_id'])??$agent;
    }
    return $agent;
}

function research_agent_ensure_default(PDO $pdo,array $viewer): ?array {
    if(!research_agent_ready($pdo)||!agent_chat_available($pdo,$viewer))return null;
    if($existing=research_agent_default($pdo,$viewer))return research_agent_activate_default($pdo,$viewer,$existing);
    try{
        return research_agent_create($pdo,$viewer,[
            'name'=>'Research Agent',
            'description'=>'Your default Annotated Research Agent. Add annotations, sources, and Research context here for ongoing evidence review, monitoring, and follow-up.',
            'cadence'=>'daily',
            'timezone_name'=>'UTC',
            'run_time_local'=>'09:00',
        ],true);
    }catch(PDOException $e){
        if((string)$e->getCode()!=='23000')throw $e;
        $existing=research_agent_default($pdo,$viewer);
        return $existing?research_agent_activate_default($pdo,$viewer,$existing):null;
    }
}

function research_agent_workspace_options(PDO $pdo,array $viewer): array {
    $q=$pdo->prepare("SELECT t.public_id,t.name FROM teams t JOIN team_members tm ON tm.team_id=t.id
      WHERE tm.user_id=? AND tm.role IN ('owner','admin','researcher') ORDER BY t.name");
    $q->execute([(int)$viewer['id']]);
    return $q->fetchAll()?:[];
}


function research_agent_update_profile(PDO $pdo,array $viewer,string $publicId,array $input): array {
    $agent=research_agent_access($pdo,$viewer,$publicId);if(!$agent)throw new RuntimeException('Research Agent not found.');
    $canEdit=(int)$agent['owner_user_id']===(int)$viewer['id']||in_array((string)($agent['team_role']??''),['owner','admin'],true);
    if(!$canEdit)throw new RuntimeException('You do not have permission to edit this Research Agent.');
    $name=mb_substr(trim((string)($input['name']??$agent['name'])),0,190);if($name==='')throw new InvalidArgumentException('Research Agent name is required.');
    $description=mb_substr(trim((string)($input['description']??$agent['description']??'')),0,4000);
    $image=mb_substr(trim((string)($input['profile_image_url']??$agent['profile_image_url']??'')),0,500);
    if($image!==''&&!preg_match('#^(https?://|/uploads/)#i',$image))throw new InvalidArgumentException('Profile image must be an HTTPS URL or uploaded image path.');
    $visibility=strtolower(trim((string)($input['visibility']??$agent['visibility']??'private')));if(!in_array($visibility,['private','friends','public'],true))throw new InvalidArgumentException('Invalid Research Agent visibility.');
    $pdo->prepare('UPDATE research_agents SET name=?,description=?,profile_image_url=?,visibility=?,updated_at=NOW() WHERE id=?')->execute([$name,$description!==''?$description:null,$image!==''?$image:null,$visibility,(int)$agent['id']]);
    $pdo->prepare('UPDATE research_projects SET title=?,description=?,updated_at=NOW() WHERE id=?')->execute([$name,$description!==''?$description:null,(int)$agent['project_id']]);
    $pdo->prepare('UPDATE conversations SET title=?,updated_at=NOW() WHERE id=?')->execute([$name,(int)$agent['conversation_id']]);
    return research_agent_access($pdo,$viewer,$publicId)??$agent;
}
