<?php
declare(strict_types=1);

function research_agent_stories_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_agent_stories')&&installer_table_exists($pdo,'research_agent_story_states');}
    catch(Throwable $e){return false;}
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
    if(!research_agent_stories_ready($pdo))return null;
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
    return "(
      ra.owner_user_id=:viewer_owner
      OR EXISTS(SELECT 1 FROM team_members tmx WHERE tmx.team_id=ra.team_id AND tmx.user_id=:viewer_team)
      OR (
        (
          (ra.visibility='public' AND EXISTS(SELECT 1 FROM follows ff WHERE ff.follower_user_id=:viewer_follow AND ff.followed_user_id=ra.owner_user_id))
          OR (
            ra.visibility='friends'
            AND EXISTS(SELECT 1 FROM follows ff1 WHERE ff1.follower_user_id=:viewer_friend1 AND ff1.followed_user_id=ra.owner_user_id)
            AND EXISTS(SELECT 1 FROM follows ff2 WHERE ff2.follower_user_id=ra.owner_user_id AND ff2.followed_user_id=:viewer_friend2)
          )
        )
        AND NOT EXISTS(
          SELECT 1 FROM blocks b
          WHERE (b.blocker_user_id=:viewer_block1 AND b.blocked_user_id=ra.owner_user_id)
             OR (b.blocker_user_id=ra.owner_user_id AND b.blocked_user_id=:viewer_block2)
        )
      )
    )";
}
function research_agent_story_social_params(array $viewer): array {
    $id=(int)$viewer['id'];
    return [':viewer_owner'=>$id,':viewer_team'=>$id,':viewer_follow'=>$id,':viewer_friend1'=>$id,':viewer_friend2'=>$id,':viewer_block1'=>$id,':viewer_block2'=>$id];
}
function research_agent_story_notify_social(PDO $pdo,array $viewer,array $agent,array $story): int {
    $visibility=(string)($agent['visibility']??'private');if(!in_array($visibility,['public','friends'],true))return 0;
    $owner=(int)$agent['owner_user_id'];if($owner<1)return 0;
    $sql="SELECT u.id FROM users u JOIN follows f ON f.follower_user_id=u.id AND f.followed_user_id=? WHERE u.status='active'
      AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.blocker_user_id=u.id AND b.blocked_user_id=?) OR (b.blocker_user_id=? AND b.blocked_user_id=u.id))";
    if($visibility==='friends')$sql.=" AND EXISTS(SELECT 1 FROM follows f2 WHERE f2.follower_user_id=? AND f2.followed_user_id=u.id)";
    $q=$pdo->prepare($sql);$params=[$owner,$owner,$owner];if($visibility==='friends')$params[]=$owner;$q->execute($params);
    $sent=0;foreach($q->fetchAll(PDO::FETCH_COLUMN) as $uid){
        $uid=(int)$uid;if($uid===$owner)continue;
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
      JOIN users u ON u.id=ra.owner_user_id
      LEFT JOIN teams t ON t.id=ra.team_id
      LEFT JOIN research_agent_story_states st ON st.story_id=s.id AND st.user_id=:viewer_state
      WHERE s.public_id=:story_public AND ".research_agent_story_social_visibility_sql()." LIMIT 1";
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
      JOIN users u ON u.id=ra.owner_user_id
      LEFT JOIN teams t ON t.id=ra.team_id
      LEFT JOIN research_agent_story_states st ON st.story_id=s.id AND st.user_id=:viewer_state
      WHERE ra.status<>'archived' AND (s.expires_at IS NULL OR s.expires_at>NOW()) AND ".research_agent_story_social_visibility_sql().$dismiss."
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
