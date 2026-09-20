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
    return max(0,min(100,cognitive_feed_base_score($type)+cognitive_feed_priority_bonus($priority)+cognitive_feed_recency_bonus($createdAt)+$extra));
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
