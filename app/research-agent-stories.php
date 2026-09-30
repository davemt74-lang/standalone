<?php
declare(strict_types=1);

function research_agent_stories_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_agent_stories')&&installer_table_exists($pdo,'research_agent_story_states');}
    catch(Throwable $e){return false;}
}
function research_agent_story_authoring_ready(PDO $pdo): bool {
    if(!research_agent_stories_ready($pdo))return false;
    try{$db=installer_database_name($pdo);$q=$pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=? AND table_name='research_agent_stories' AND column_name='status' LIMIT 1");$q->execute([$db]);return (bool)$q->fetchColumn();}
    catch(Throwable $e){return false;}
}
function research_agent_story_published_filter(PDO $pdo,string $alias='s'): string {
    return research_agent_story_authoring_ready($pdo)?' AND '.$alias.".status='published'":'';
}
function research_agent_story_agent_for_refs(PDO $pdo,array $viewer,array $refs): ?array {
    foreach($refs as $ref){
        $type=(string)($ref['type']??'');$public=trim((string)($ref['public_id']??''));
        if($public===''||!in_array($type,['project','research'],true))continue;
        $q=$pdo->prepare("SELECT ra.public_id FROM research_agents ra JOIN research_projects rp ON rp.id=ra.project_id
          LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=?
          WHERE rp.public_id=? AND ra.status<>'archived'
            AND ((ra.team_id IS NULL AND ra.owner_user_id=?) OR (ra.team_id IS NOT NULL AND tm.user_id=?))
          LIMIT 1");
        $q->execute([(int)$viewer['id'],$public,(int)$viewer['id'],(int)$viewer['id']]);
        $agentPublic=(string)($q->fetchColumn()?:'');
        if($agentPublic!=='')return research_agent_access($pdo,$viewer,$agentPublic);
    }
    return null;
}
function research_agent_story_type(array $item): string {
    $type=strtolower((string)($item['type']??''));
    $title=strtolower((string)($item['title']??''));
    if(str_contains($type,'evidence')||str_contains($title,'evidence'))return 'evidence';
    if(str_contains($type,'risk')||str_contains($title,'risk')||str_contains($type,'conflict'))return 'risk';
    if(str_contains($type,'decision'))return 'decision';
    if(str_contains($type,'task')||str_contains($type,'action'))return 'task';
    if(str_contains($type,'question')||str_contains($title,'question'))return 'question';
    return 'update';
}
function research_agent_story_object_from_refs(array $refs): array {
    foreach($refs as $ref){
        $type=(string)($ref['type']??'');$public=(string)($ref['public_id']??'');
        if($public===''||$type==='project'||$type==='research')continue;
        return ['type'=>$type,'public_id'=>$public];
    }
    foreach($refs as $ref){
        if(in_array((string)($ref['type']??''),['project','research'],true)&&!empty($ref['public_id']))return ['type'=>'project','public_id'=>(string)$ref['public_id']];
    }
    return ['type'=>null,'public_id'=>null];
}
function research_agent_story_deterministic_body(array $item): string {
    $parts=[];
    foreach(['body','why'] as $key){$v=trim((string)($item[$key]??''));if($v!==''&&!in_array($v,$parts,true))$parts[]=$v;}
    if(!$parts)$parts[]='I found a research update that may be useful to review.';
    return mb_substr(implode(" ",$parts),0,1800);
}
function research_agent_story_llm_available(PDO $pdo,array $config): array {
    try{
        if(!function_exists('ai_setting_model_id')||!function_exists('ai_model_record'))return ['available'=>false,'model_id'=>0];
        $modelId=ai_setting_model_id($pdo,'research');if($modelId<=0)return ['available'=>false,'model_id'=>0];
        $m=ai_model_record($pdo,$modelId);
        $hasKey=trim((string)($m['api_key_ciphertext']??''))!=='';
        if(!$hasKey)return ['available'=>false,'model_id'=>$modelId,'reason'=>'missing_key'];
        ai_decrypt_secret($config,(string)$m['api_key_ciphertext']);
        return ['available'=>true,'model_id'=>$modelId,'provider'=>(string)$m['provider_label'],'model'=>(string)$m['display_name']];
    }catch(Throwable $e){return ['available'=>false,'model_id'=>0,'reason'=>'unavailable'];}
}
function research_agent_story_queue_enhancement(PDO $pdo,array $config,array $viewer,string $storyPublicId): bool {
    if(!function_exists('ai_queue_job'))return false;
    $llm=research_agent_story_llm_available($pdo,$config);if(empty($llm['available']))return false;
    $q=$pdo->prepare("UPDATE research_agent_stories SET generation_status='queued',updated_at=NOW() WHERE public_id=? AND generation_status='ready'");
    $q->execute([$storyPublicId]);if($q->rowCount()<1)return false;
    ai_queue_job($pdo,(int)$viewer['id'],'research_agent_story',(int)$llm['model_id'],'research_agent_story',$storyPublicId,[],4);
    return true;
}
function research_agent_story_publish_from_item(PDO $pdo,array $config,array $viewer,array $item): ?array {
    if(!research_agent_story_authoring_ready($pdo))return null;
    $refs=is_array($item['refs']??null)?$item['refs']:proactive_observation_refs($item);
    $agent=research_agent_story_agent_for_refs($pdo,$viewer,$refs);if(!$agent)return null;
    $observationKey=(string)($item['key']??'');if(!preg_match('/^[a-f0-9]{64}$/',$observationKey))$observationKey=hash('sha256',json_encode([$agent['public_id'],$item['type']??'',$item['title']??'',$refs],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    $title=mb_substr(trim((string)($item['title']??'Research update')),0,240);if($title==='')$title='Research update';
    $body=research_agent_story_deterministic_body($item);$priority=(string)($item['priority']??'medium');if(!in_array($priority,['low','medium','high'],true))$priority='medium';
    $storyType=research_agent_story_type($item);$primary=trim((string)($item['primary_url']??proactive_primary_url($item)));$object=research_agent_story_object_from_refs($refs);
    $public=ulid_like();
    $q=$pdo->prepare("INSERT IGNORE INTO research_agent_stories(public_id,agent_id,observation_key,story_type,priority,title,body,source_body,primary_url,object_type,object_public_id,expires_at)
      VALUES(?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 7 DAY))");
    $q->execute([$public,(int)$agent['id'],$observationKey,$storyType,$priority,$title,$body,$body,$primary!==''?$primary:null,$object['type'],$object['public_id']]);
    if($q->rowCount()===0){
        $q=$pdo->prepare("SELECT * FROM research_agent_stories WHERE agent_id=? AND observation_key=? LIMIT 1");$q->execute([(int)$agent['id'],$observationKey]);$story=$q->fetch()?:null;
    }else{
        $q=$pdo->prepare("SELECT * FROM research_agent_stories WHERE public_id=? LIMIT 1");$q->execute([$public]);$story=$q->fetch()?:null;
        if($story){
            if($priority==='high'){
                notification_create($pdo,(int)$viewer['id'],null,'research_agent_story','research_agent_story',(string)$story['public_id'],$title.': '.$body,[
                    'allow_self'=>true,'category'=>'research','dedupe_key'=>'research_agent_story:'.$observationKey,
                    'group_key'=>'research_agent_story:'.(string)$agent['public_id'],
                    'context'=>['agent_public_id'=>(string)$agent['public_id'],'conversation_public_id'=>(string)$agent['conversation_public_id'],'story_public_id'=>(string)$story['public_id']]
                ]);
                research_agent_story_notify_social($pdo,$viewer,$agent,$story);
            }
            research_agent_story_queue_enhancement($pdo,$config,$viewer,(string)$story['public_id']);
        }
    }
    return $story;
}

function research_agent_story_create_manual(PDO $pdo,array $viewer,string $agentPublic,array $input): array {
    if(!research_agent_story_authoring_ready($pdo))throw new RuntimeException('Story authoring requires the latest database upgrade.');
    $agent=research_agent_access($pdo,$viewer,$agentPublic);if(!$agent)throw new RuntimeException('Research Agent not found.');
    $canEdit=(int)$agent['owner_user_id']===(int)$viewer['id']||in_array((string)($agent['team_role']??''),['owner','admin'],true);
    if(!$canEdit)throw new RuntimeException('You do not have permission to publish Stories for this Research Agent.');
    $title=mb_substr(trim((string)($input['title']??'')),0,240);if($title==='')throw new InvalidArgumentException('Story title is required.');
    $body=mb_substr(trim((string)($input['body']??'')),0,1800);if($body==='')throw new InvalidArgumentException('Story body is required.');
    $storyType=strtolower(trim((string)($input['story_type']??'update')));if(!in_array($storyType,['update','evidence','risk','question','decision','task','briefing'],true))$storyType='update';
    $priority=strtolower(trim((string)($input['priority']??'medium')));if(!in_array($priority,['low','medium','high'],true))$priority='medium';
    $status=strtolower(trim((string)($input['status']??'draft')));if(!in_array($status,['draft','published'],true))$status='draft';
    $primary=mb_substr(trim((string)($input['primary_url']??'')),0,500);if($primary!==''&&!preg_match('#^https?://#i',$primary))throw new InvalidArgumentException('Story source URL must be HTTP or HTTPS.');
    $public=ulid_like();$observationKey=hash('sha256','manual|'.$agent['id'].'|'.$public);
    $q=$pdo->prepare("INSERT INTO research_agent_stories(public_id,agent_id,observation_key,story_type,priority,title,body,source_body,primary_url,generation_quality,generation_status,status,published_at,expires_at)
      VALUES(?,?,?,?,?,?,?,?,?,'deterministic','ready',?,NOW(),DATE_ADD(NOW(),INTERVAL 7 DAY))");
    $q->execute([$public,(int)$agent['id'],$observationKey,$storyType,$priority,$title,$body,$body,$primary!==''?$primary:null,$status]);
    $q=$pdo->prepare('SELECT * FROM research_agent_stories WHERE public_id=? LIMIT 1');$q->execute([$public]);$story=$q->fetch()?:[];
    if($status==='published')research_agent_story_notify_social($pdo,$viewer,$agent,$story);
    return $story;
}
function research_agent_story_publish_manual(PDO $pdo,array $viewer,string $storyPublic): array {
    $story=research_agent_story_access_draft($pdo,$viewer,$storyPublic);if(!$story)throw new RuntimeException('Story not found.');
    $agent=research_agent_access($pdo,$viewer,(string)$story['agent_public_id']);if(!$agent)throw new RuntimeException('Research Agent not found.');
    $pdo->prepare("UPDATE research_agent_stories SET status='published',published_at=NOW(),updated_at=NOW() WHERE id=? AND status='draft'")->execute([(int)$story['id']]);
    $q=$pdo->prepare('SELECT * FROM research_agent_stories WHERE id=? LIMIT 1');$q->execute([(int)$story['id']]);$fresh=$q->fetch()?:$story;
    research_agent_story_notify_social($pdo,$viewer,$agent,$fresh);return $fresh;
}
function research_agent_story_access_draft(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_agent_story_authoring_ready($pdo))return null;
    $q=$pdo->prepare("SELECT s.*,ra.public_id agent_public_id,ra.owner_user_id,tm.role team_role
      FROM research_agent_stories s JOIN research_agents ra ON ra.id=s.agent_id
      LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=?
      WHERE s.public_id=? AND ((ra.team_id IS NULL AND ra.owner_user_id=?) OR (ra.team_id IS NOT NULL AND tm.user_id=?)) LIMIT 1");
    $q->execute([(int)$viewer['id'],trim($publicId),(int)$viewer['id'],(int)$viewer['id']]);$row=$q->fetch()?:null;if(!$row)return null;
    if((int)$row['owner_user_id']!==(int)$viewer['id']&&!in_array((string)($row['team_role']??''),['owner','admin'],true))return null;
    return $row;
}
function research_agent_story_drafts(PDO $pdo,array $viewer,string $agentPublic,int $limit=30): array {
    $agent=research_agent_access($pdo,$viewer,$agentPublic);if(!$agent||!research_agent_story_authoring_ready($pdo))return [];$limit=max(1,min(100,$limit));
    $canEdit=(int)$agent['owner_user_id']===(int)$viewer['id']||in_array((string)($agent['team_role']??''),['owner','admin'],true);if(!$canEdit)return [];
    $q=$pdo->prepare("SELECT * FROM research_agent_stories WHERE agent_id=? AND status='draft' ORDER BY updated_at DESC,id DESC LIMIT ".$limit);
    $q->execute([(int)$agent['id']]);return $q->fetchAll()?:[];
}

function research_agent_story_sync(PDO $pdo,array $config,array $viewer,int $limit=8): array {
    if(!research_agent_stories_ready($pdo)||!function_exists('proactive_briefing'))return ['ready'=>false,'created'=>0];
    $brief=proactive_briefing($pdo,$viewer,max(3,min(8,$limit)));$created=0;
    foreach((array)($brief['items']??[]) as $item){
        $before=(int)$pdo->query('SELECT ROW_COUNT()')->fetchColumn();
        $story=research_agent_story_publish_from_item($pdo,$config,$viewer,$item);
        if($story)$created++;
    }
    return ['ready'=>true,'created'=>$created];
}
function research_agent_story_social_visibility_sql(): string {
    return research_agent_social_visibility_sql(true);
}
function research_agent_story_social_params(array $viewer): array {
    return research_agent_social_visibility_params($viewer);
}
function research_agent_story_social_recipients(PDO $pdo,array $agent): array {
    $visibility=(string)($agent['visibility']??'private');if(!in_array($visibility,['public','friends'],true))return [];
    $owner=(int)($agent['owner_user_id']??0);if($owner<1)return [];
    $sql="SELECT u.id FROM users u JOIN follows f ON f.follower_user_id=u.id AND f.followed_user_id=? WHERE u.status='active'
      AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.blocker_user_id=u.id AND b.blocked_user_id=?) OR (b.blocker_user_id=? AND b.blocked_user_id=u.id))";
    if($visibility==='friends')$sql.=" AND EXISTS(SELECT 1 FROM follows f2 WHERE f2.follower_user_id=? AND f2.followed_user_id=u.id)";
    $q=$pdo->prepare($sql);$params=[$owner,$owner,$owner];if($visibility==='friends')$params[]=$owner;$q->execute($params);
    return array_values(array_filter(array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)),static fn($id)=>$id>0&&$id!==$owner));
}
function research_agent_story_reconcile_social_delivery(PDO $pdo,array $viewer,int $ownerUserId): array {
    $viewerId=(int)($viewer['id']??0);if($viewerId<1||$ownerUserId<1)return ['archived'=>0,'visible_story_count'=>0];
    $q=$pdo->prepare("SELECT n.public_id,n.object_public_id FROM notifications n
      JOIN research_agent_stories s ON s.public_id=n.object_public_id
      JOIN research_agents ra ON ra.id=s.agent_id
      WHERE n.user_id=? AND n.object_type='research_agent_story' AND n.archived_at IS NULL AND ra.owner_user_id=?");
    $q->execute([$viewerId,$ownerUserId]);$archived=0;
    foreach($q->fetchAll() as $row){
        if(research_agent_story_access($pdo,$viewer,(string)$row['object_public_id']))continue;
        $u=$pdo->prepare('UPDATE notifications SET archived_at=COALESCE(archived_at,NOW()) WHERE user_id=? AND public_id=? AND archived_at IS NULL');
        $u->execute([$viewerId,(string)$row['public_id']]);$archived+=$u->rowCount();
    }
    $count=0;foreach(research_agent_story_list($pdo,$viewer,60) as $story)if((int)($story['owner_user_id']??0)===$ownerUserId)$count++;
    return ['archived'=>$archived,'visible_story_count'=>$count];
}
function research_agent_story_notify_social(PDO $pdo,array $viewer,array $agent,array $story): int {
    $owner=(int)($agent['owner_user_id']??0);if($owner<1)return 0;$sent=0;
    foreach(research_agent_story_social_recipients($pdo,$agent) as $uid){
        if(notification_create($pdo,$uid,$owner,'research_agent_story','research_agent_story',(string)$story['public_id'],(string)$agent['name'].': '.mb_substr((string)$story['body'],0,320),[
          'category'=>'research','dedupe_key'=>'research-agent-story:'.$story['public_id'].':'.$uid,
          'group_key'=>'research-agent-story:'.$agent['public_id'],
          'context'=>['agent_public_id'=>(string)$agent['public_id'],'conversation_public_id'=>(string)$agent['conversation_public_id'],'story_public_id'=>(string)$story['public_id']]
        ]))$sent++;
    }return $sent;
}
function research_agent_story_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_agent_stories_ready($pdo))return null;
    $sql="SELECT s.*,ra.public_id agent_public_id,ra.name agent_name,ra.profile_image_url,ra.visibility,ra.owner_user_id,ra.conversation_id,c.public_id conversation_public_id,u.username owner_username,u.display_name owner_display_name,
      CASE WHEN ra.owner_user_id=:viewer_owner_rank OR EXISTS(SELECT 1 FROM team_members tr WHERE tr.team_id=ra.team_id AND tr.user_id=:viewer_team_rank) THEN 0 ELSE 1 END social_rank,
      rp.public_id project_public_id,t.public_id team_public_id,t.name team_name,st.viewed_at,st.dismissed_at
      FROM research_agent_stories s
      JOIN research_agents ra ON ra.id=s.agent_id
      JOIN research_projects rp ON rp.id=ra.project_id
      JOIN conversations c ON c.id=ra.conversation_id
      JOIN users u ON u.id=ra.owner_user_id AND u.status='active'
      LEFT JOIN teams t ON t.id=ra.team_id
      LEFT JOIN research_agent_story_states st ON st.story_id=s.id AND st.user_id=:viewer_state
      WHERE s.public_id=:story_public AND ra.status<>'archived' AND (s.expires_at IS NULL OR s.expires_at>NOW())".research_agent_story_published_filter($pdo,'s')." AND ".research_agent_story_social_visibility_sql()." LIMIT 1";
    $q=$pdo->prepare($sql);$params=research_agent_story_social_params($viewer);$params[':viewer_owner_rank']=(int)$viewer['id'];$params[':viewer_team_rank']=(int)$viewer['id'];$params[':viewer_state']=(int)$viewer['id'];$params[':story_public']=$publicId;$q->execute($params);
    $row=$q->fetch()?:null;if(!$row)return null;$row['story_url']='/home.php?story='.rawurlencode((string)$row['public_id']);return $row;
}
function research_agent_story_list(PDO $pdo,array $viewer,int $limit=20,bool $includeDismissed=false): array {
    if(!research_agent_stories_ready($pdo))return [];
    $limit=max(1,min(60,$limit));$dismiss=$includeDismissed?'':' AND st.dismissed_at IS NULL';
    $sql="SELECT s.*,ra.public_id agent_public_id,ra.name agent_name,ra.profile_image_url,ra.visibility,ra.owner_user_id,c.public_id conversation_public_id,u.username owner_username,u.display_name owner_display_name,
      rp.public_id project_public_id,t.public_id team_public_id,t.name team_name,st.viewed_at,st.dismissed_at,
      CASE WHEN ra.owner_user_id=:viewer_owner_rank OR EXISTS(SELECT 1 FROM team_members tr WHERE tr.team_id=ra.team_id AND tr.user_id=:viewer_team_rank) THEN 0 ELSE 1 END social_rank
      FROM research_agent_stories s
      JOIN research_agents ra ON ra.id=s.agent_id
      JOIN research_projects rp ON rp.id=ra.project_id
      JOIN conversations c ON c.id=ra.conversation_id
      JOIN users u ON u.id=ra.owner_user_id AND u.status='active'
      LEFT JOIN teams t ON t.id=ra.team_id
      LEFT JOIN research_agent_story_states st ON st.story_id=s.id AND st.user_id=:viewer_state
      WHERE ra.status<>'archived' AND (s.expires_at IS NULL OR s.expires_at>NOW())".research_agent_story_published_filter($pdo,'s')." AND ".research_agent_story_social_visibility_sql().$dismiss."
      ORDER BY social_rank ASC,(st.viewed_at IS NULL) DESC,s.priority='high' DESC,s.published_at DESC,s.id DESC LIMIT ".$limit;
    $q=$pdo->prepare($sql);$params=research_agent_story_social_params($viewer);$params[':viewer_owner_rank']=(int)$viewer['id'];$params[':viewer_team_rank']=(int)$viewer['id'];$params[':viewer_state']=(int)$viewer['id'];$q->execute($params);
    $rows=$q->fetchAll()?:[];foreach($rows as &$row)$row['story_url']='/home.php?story='.rawurlencode((string)$row['public_id']);unset($row);return $rows;
}
function research_agent_story_groups(PDO $pdo,array $viewer,int $limit=40): array {
    $rows=research_agent_story_list($pdo,$viewer,$limit);$groups=[];
    foreach($rows as $story){$key=(string)$story['agent_public_id'];if(!isset($groups[$key]))$groups[$key]=[
      'agent_public_id'=>$key,'agent_name'=>(string)$story['agent_name'],'profile_image_url'=>(string)($story['profile_image_url']??''),
      'conversation_public_id'=>(string)($story['conversation_public_id']??''),'owner_username'=>(string)($story['owner_username']??''),
      'social_rank'=>(int)($story['social_rank']??1),'stories'=>[],'unread_count'=>0,'latest_at'=>(string)$story['published_at']
    ];
    $groups[$key]['stories'][]=$story;if(empty($story['viewed_at']))$groups[$key]['unread_count']++;
    }
    return array_values($groups);
}
function research_agent_story_state(PDO $pdo,array $viewer,string $publicId,string $action): bool {
    $story=research_agent_story_access($pdo,$viewer,$publicId);if(!$story)return false;
    if($action==='view')$sql="INSERT INTO research_agent_story_states(story_id,user_id,viewed_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE viewed_at=COALESCE(viewed_at,NOW()),updated_at=NOW()";
    elseif($action==='dismiss')$sql="INSERT INTO research_agent_story_states(story_id,user_id,viewed_at,dismissed_at) VALUES(?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE viewed_at=COALESCE(viewed_at,NOW()),dismissed_at=NOW(),updated_at=NOW()";
    else throw new InvalidArgumentException('Invalid story state.');
    $pdo->prepare($sql)->execute([(int)$story['id'],(int)$viewer['id']]);return true;
}
function research_agent_story_apply_ai_output(PDO $pdo,string $publicId,string $text,string $runPublicId): bool {
    $text=trim($text);if($text==='')return false;
    $text=preg_replace('/^["“]|["”]$/u','',$text)??$text;$text=mb_substr($text,0,1800);
    $q=$pdo->prepare("UPDATE research_agent_stories SET body=?,generation_quality='llm',generation_status='enhanced',ai_run_public_id=?,updated_at=NOW() WHERE public_id=? AND generation_status='queued'");
    $q->execute([$text,$runPublicId,$publicId]);return $q->rowCount()===1;
}
function research_agent_story_mark_failed(PDO $pdo,string $publicId): void {
    $pdo->prepare("UPDATE research_agent_stories SET generation_status='failed',updated_at=NOW() WHERE public_id=? AND generation_status='queued'")->execute([$publicId]);
}
function research_agent_story_activity(PDO $pdo,array $viewer,int $limit=20): array {
    $out=[];foreach(research_agent_story_list($pdo,$viewer,$limit,true) as $s){
        $out[]=[
          'key'=>'agent_story:'.$s['public_id'],'type'=>'research_agent_story','created_at'=>$s['published_at'],
          'title'=>$s['agent_name'].' posted a Story','body'=>$s['body'],
          'href'=>(string)($s['story_url']??'/home.php'),
          'object'=>['type'=>'research_agent','public_id'=>$s['agent_public_id'],'label'=>$s['agent_name']],
          'context'=>[['type'=>'project','public_id'=>$s['project_public_id'],'label'=>$s['agent_name'].' Research']]
        ];
    }return $out;
}
