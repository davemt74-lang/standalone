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

function research_agent_list(PDO $pdo,array $viewer,int $limit=30): array {
    if(!research_agent_ready($pdo))return [];
    $limit=max(1,min(50,$limit));
    $q=$pdo->prepare("SELECT ra.public_id,ra.name,ra.description,ra.status,ra.monitoring_cadence,ra.is_default,ra.updated_at,
      rp.public_id project_public_id,rp.title project_title,
      c.public_id conversation_public_id,COALESCE(c.last_message_at,c.updated_at,c.created_at) activity_at,
      (SELECT m.body FROM conversation_messages m WHERE m.conversation_id=c.id AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 1) last_message,
      t.public_id team_public_id,t.name team_name
      FROM research_agents ra
      JOIN research_projects rp ON rp.id=ra.project_id AND rp.status<>'archived'
      JOIN conversations c ON c.id=ra.conversation_id AND c.conversation_type='agent'
      LEFT JOIN teams t ON t.id=ra.team_id
      LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=?
      WHERE ra.status<>'archived' AND (ra.owner_user_id=? OR tm.user_id=?)
      ORDER BY COALESCE(c.last_message_at,ra.updated_at,ra.created_at) DESC,ra.id DESC
      LIMIT ".$limit);
    $q->execute([(int)$viewer['id'],(int)$viewer['id'],(int)$viewer['id']]);
    $rows=$q->fetchAll()?:[];
    foreach($rows as &$row)$row['last_message']=mb_substr(trim((string)($row['last_message']??'')),0,120);
    unset($row);
    return $rows;
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
      WHERE ra.public_id=? AND (ra.owner_user_id=? OR tm.user_id=?) LIMIT 1");
    $q->execute([(int)$viewer['id'],$publicId,(int)$viewer['id'],(int)$viewer['id']]);
    return $q->fetch()?:null;
}

function research_agent_team(PDO $pdo,array $viewer,string $teamPublicId): ?array {
    $teamPublicId=trim($teamPublicId);if($teamPublicId==='')return null;
    $q=$pdo->prepare("SELECT t.id,t.public_id,t.name,tm.role FROM teams t
      JOIN team_members tm ON tm.team_id=t.id AND tm.user_id=?
      WHERE t.public_id=? AND tm.role IN ('owner','admin','researcher') LIMIT 1");
    $q->execute([(int)$viewer['id'],$teamPublicId]);
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

        $pdo->prepare("INSERT INTO research_agents(public_id,owner_user_id,team_id,project_id,conversation_id,automation_id,name,description,status,monitoring_cadence,is_default)
          VALUES(?,?,?,?,?,?,?,?, 'active',?,?)")
          ->execute([$agentPublic,(int)$viewer['id'],$team['id']??null,$projectId,$conversationId,$automationId,$name,$description!==''?$description:null,$cadence,$isDefault?1:null]);

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
    $q=$pdo->prepare("SELECT public_id FROM research_agents WHERE owner_user_id=? AND is_default=1 AND status<>'archived' ORDER BY id ASC LIMIT 1");
    $q->execute([(int)$viewer['id']]);
    $public=(string)($q->fetchColumn()?:'');
    return $public!==''?research_agent_access($pdo,$viewer,$public):null;
}

function research_agent_ensure_default(PDO $pdo,array $viewer): ?array {
    if(!research_agent_ready($pdo)||!agent_chat_available($pdo,$viewer))return null;
    if($existing=research_agent_default($pdo,$viewer))return $existing;
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
        return research_agent_default($pdo,$viewer);
    }
}

function research_agent_workspace_options(PDO $pdo,array $viewer): array {
    $q=$pdo->prepare("SELECT t.public_id,t.name FROM teams t JOIN team_members tm ON tm.team_id=t.id
      WHERE tm.user_id=? AND tm.role IN ('owner','admin','researcher') ORDER BY t.name");
    $q->execute([(int)$viewer['id']]);
    return $q->fetchAll()?:[];
}
