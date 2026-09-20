<?php
declare(strict_types=1);

require_once __DIR__.'/research-reports.php';

function living_research_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_report_subscriptions')&&installer_table_exists($pdo,'research_report_reader_state')&&installer_table_exists($pdo,'research_report_topics');}
    catch(Throwable $e){return false;}
}

function living_research_topic_slug(string $label): string {
    $label=mb_strtolower(trim($label));$label=preg_replace('/[^\p{L}\p{N}]+/u','-',$label)??'';$label=trim($label,'-');return mb_substr($label,0,80);
}

function living_research_parse_topics(string|array $value): array {
    $items=is_array($value)?$value:preg_split('/[,\n]+/u',$value);$out=[];foreach($items?:[] as $item){$label=mb_substr(trim((string)$item),0,120);if($label==='')continue;$slug=living_research_topic_slug($label);if($slug==='')continue;if(!isset($out[$slug]))$out[$slug]=$label;if(count($out)>=8)break;}return $out;
}

function living_research_topics(PDO $pdo,int $reportId): array {
    if(!living_research_ready($pdo))return [];$q=$pdo->prepare('SELECT topic_slug,topic_label,position FROM research_report_topics WHERE report_id=? ORDER BY position,topic_label');$q->execute([$reportId]);return $q->fetchAll();
}

function living_research_set_topics(PDO $pdo,int $reportId,string|array $topics): void {
    if(!living_research_ready($pdo))return;$parsed=living_research_parse_topics($topics);$own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();try{$pdo->prepare('DELETE FROM research_report_topics WHERE report_id=?')->execute([$reportId]);$q=$pdo->prepare('INSERT INTO research_report_topics(report_id,topic_slug,topic_label,position) VALUES(?,?,?,?)');$pos=0;foreach($parsed as $slug=>$label)$q->execute([$reportId,$slug,$label,$pos++]);if($own)$pdo->commit();}catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function living_research_subscription(PDO $pdo,array $viewer,array $report): array {
    if(!living_research_ready($pdo)||!$viewer)return ['subscribed'=>false,'notify_updates'=>false];$q=$pdo->prepare('SELECT notify_updates,created_at,updated_at FROM research_report_subscriptions WHERE user_id=? AND report_id=? LIMIT 1');$q->execute([$viewer['id'],$report['id']]);$r=$q->fetch();return $r?['subscribed'=>true,'notify_updates'=>(bool)$r['notify_updates'],'created_at'=>$r['created_at'],'updated_at'=>$r['updated_at']]:['subscribed'=>false,'notify_updates'=>false];
}

function living_research_set_subscription(PDO $pdo,array $viewer,array $report,bool $subscribed,bool $notify=true): bool {
    if(!living_research_ready($pdo))throw new RuntimeException('Living Research requires the Phase 24 database upgrade.');
    if(!research_report_access($pdo,(string)$report['public_id'],$viewer))throw new RuntimeException('Research report is unavailable.');
    if($subscribed){$pdo->prepare("INSERT INTO research_report_subscriptions(user_id,report_id,notify_updates) VALUES(?,?,?) ON DUPLICATE KEY UPDATE notify_updates=VALUES(notify_updates),updated_at=NOW()")->execute([$viewer['id'],$report['id'],$notify?1:0]);return true;}
    $q=$pdo->prepare('DELETE FROM research_report_subscriptions WHERE user_id=? AND report_id=?');$q->execute([$viewer['id'],$report['id']]);return $q->rowCount()>0;
}

function living_research_reader_state(PDO $pdo,array $viewer,array $report): array {
    if(!living_research_ready($pdo)||!$viewer)return ['last_read_version_id'=>null,'last_read_version_number'=>null,'last_read_at'=>null];
    $q=$pdo->prepare("SELECT rrs.last_read_version_id,rrs.last_read_at,rv.version_number last_read_version_number FROM research_report_reader_state rrs LEFT JOIN research_report_versions rv ON rv.id=rrs.last_read_version_id WHERE rrs.user_id=? AND rrs.report_id=? LIMIT 1");$q->execute([$viewer['id'],$report['id']]);$r=$q->fetch();
    return $r?:['last_read_version_id'=>null,'last_read_version_number'=>null,'last_read_at'=>null];
}

function living_research_mark_read(PDO $pdo,array $viewer,array $report,int $versionId): void {
    if(!living_research_ready($pdo)||$versionId<=0)return;$q=$pdo->prepare('SELECT report_id FROM research_report_versions WHERE id=? LIMIT 1');$q->execute([$versionId]);if((int)$q->fetchColumn()!==(int)$report['id'])throw new RuntimeException('Report version does not belong to this report.');
    $pdo->prepare("INSERT INTO research_report_reader_state(user_id,report_id,last_read_version_id,last_read_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE last_read_version_id=VALUES(last_read_version_id),last_read_at=NOW(),updated_at=NOW()")->execute([$viewer['id'],$report['id'],$versionId]);
}

function living_research_read_status(PDO $pdo,array $viewer,array $report): array {
    $state=living_research_reader_state($pdo,$viewer,$report);$current=(int)($report['version_number']??0);$last=$state['last_read_version_number']!==null?(int)$state['last_read_version_number']:null;
    return ['current_version'=>$current,'last_read_version'=>$last,'last_read_at'=>$state['last_read_at'],'updated_since_read'=>$last!==null&&$current>$last,'first_read'=>$last===null];
}

function living_research_subscriber_count(PDO $pdo,int $reportId): int {
    if(!living_research_ready($pdo))return 0;$q=$pdo->prepare('SELECT COUNT(*) FROM research_report_subscriptions WHERE report_id=?');$q->execute([$reportId]);return (int)$q->fetchColumn();
}

function living_research_notify_subscribers(PDO $pdo,array $report,int $versionNumber,int $actorUserId): int {
    if(!living_research_ready($pdo))return 0;$q=$pdo->prepare('SELECT user_id FROM research_report_subscriptions WHERE report_id=? AND notify_updates=1');$q->execute([$report['id']]);$count=0;
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $uid){$uid=(int)$uid;$body='Research report updated to version '.$versionNumber.': '.$report['title'];$created=notification_create($pdo,$uid,$actorUserId,'research_report_updated','research_report',(string)$report['public_id'],$body,['category'=>'research','dedupe_key'=>'research-report:'.$report['public_id'].':v'.$versionNumber,'group_key'=>'research-report:'.$report['public_id'],'context'=>['report_public_id'=>$report['public_id'],'version_number'=>$versionNumber]]);if($created)$count++;}
    return $count;
}

function living_research_version_rows(PDO $pdo,array $report): array {
    $q=$pdo->prepare('SELECT id,public_id,version_number,visibility,title,summary,snapshot_hash,created_at FROM research_report_versions WHERE report_id=? ORDER BY version_number DESC');$q->execute([$report['id']]);return $q->fetchAll();
}

function living_research_map_by_id(array $items): array {$out=[];foreach($items as $item)if(isset($item['id']))$out[(string)$item['id']]=$item;return $out;}

function living_research_changed_fields(array $before,array $after,array $fields): array {
    $changed=[];foreach($fields as $field)if(json_encode($before[$field]??null,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)!==json_encode($after[$field]??null,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))$changed[]=$field;return $changed;
}

function living_research_diff_collection(array $before,array $after,array $fields): array {
    $a=living_research_map_by_id($before);$b=living_research_map_by_id($after);$added=[];$removed=[];$changed=[];
    foreach($b as $id=>$item)if(!isset($a[$id]))$added[]=$item;else{$fieldsChanged=living_research_changed_fields($a[$id],$item,$fields);if($fieldsChanged)$changed[]=['id'=>$id,'fields'=>$fieldsChanged,'before'=>$a[$id],'after'=>$item];}
    foreach($a as $id=>$item)if(!isset($b[$id]))$removed[]=$item;
    return ['added'=>$added,'removed'=>$removed,'changed'=>$changed,'counts'=>['added'=>count($added),'removed'=>count($removed),'changed'=>count($changed)]];
}

function living_research_version_diff(PDO $pdo,array $report,?array $viewer,int $fromVersion,int $toVersion): array {
    if($fromVersion<1||$toVersion<1||$fromVersion===$toVersion)throw new InvalidArgumentException('Choose two different report versions.');
    $from=research_report_version_access($pdo,$report,$fromVersion,$viewer);$to=research_report_version_access($pdo,$report,$toVersion,$viewer);if(!$from||!$to)throw new RuntimeException('One or both report versions are unavailable.');
    $a=json_decode((string)$from['snapshot_json'],true);$b=json_decode((string)$to['snapshot_json'],true);if(!is_array($a)||!is_array($b))throw new RuntimeException('Report snapshot is invalid.');
    $diff=[
      'sources'=>living_research_diff_collection((array)($a['sources']??[]),(array)($b['sources']??[]),['title','url','status','version','content_hash']),
      'claims'=>living_research_diff_collection((array)($a['claims']??[]),(array)($b['claims']??[]),['statement','type','status','resolution_note','evidence']),
      'findings'=>living_research_diff_collection((array)($a['findings']??[]),(array)($b['findings']??[]),['title','summary','status','claims']),
      'entities'=>living_research_diff_collection((array)($a['entities']??[]),(array)($b['entities']??[]),['type','name','description','status','mentions'])
    ];
    $totals=['added'=>0,'removed'=>0,'changed'=>0];foreach($diff as $section)foreach($totals as $k=>$_)$totals[$k]+=$section['counts'][$k];
    return ['from'=>$from,'to'=>$to,'from_snapshot'=>$a,'to_snapshot'=>$b,'sections'=>$diff,'totals'=>$totals];
}

function living_research_staleness(PDO $pdo,array $viewer,array $report): ?array {
    if(!function_exists('change_impact_subject_latest_event'))return null;$project=project_access($pdo,(int)$viewer['id'],(string)$report['project_public_id']);if(!$project)return null;$version=research_report_version($pdo,$report,(int)$report['version_number']);if(!$version)return null;$event=change_impact_subject_latest_event($pdo,$viewer,'report_version',(string)$version['public_id'],(string)$version['created_at']);if(!$event)return null;
    return ['event_id'=>$event['id'],'source_public_id'=>$event['source_public_id'],'source_title'=>$event['source_title']?:$event['domain'],'created_at'=>$event['created_at'],'version_number'=>(int)$version['version_number'],'reason'=>'Upstream source evidence changed after the current published version.'];
}

function living_research_subscribed_reports(PDO $pdo,array $viewer,int $limit=100): array {
    if(!living_research_ready($pdo))return [];$limit=max(1,min(200,$limit));$q=$pdo->prepare("SELECT rr.public_id FROM research_report_subscriptions rrs JOIN research_reports rr ON rr.id=rrs.report_id WHERE rrs.user_id=? ORDER BY rrs.updated_at DESC LIMIT ".$limit);$q->execute([$viewer['id']]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $public){$report=research_report_access($pdo,(string)$public,$viewer);if(!$report)continue;$report['subscription']=living_research_subscription($pdo,$viewer,$report);$report['read_status']=living_research_read_status($pdo,$viewer,$report);$report['topics']=living_research_topics($pdo,(int)$report['id']);$out[]=$report;}return $out;
}

function living_research_managed_reports(PDO $pdo,array $viewer,int $limit=100): array {
    $limit=max(1,min(200,$limit));$uid=(int)$viewer['id'];$q=$pdo->prepare("SELECT DISTINCT rr.public_id FROM research_reports rr JOIN research_projects rp ON rp.id=rr.project_id LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=? WHERE rp.owner_user_id=? OR tm.role IN ('owner','admin') ORDER BY rr.updated_at DESC LIMIT ".$limit);$q->execute([$uid,$uid]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $public){$report=research_report_access($pdo,(string)$public,$viewer);if(!$report)continue;$report['topics']=living_research_topics($pdo,(int)$report['id']);$report['subscriber_count']=living_research_subscriber_count($pdo,(int)$report['id']);$report['staleness']=living_research_staleness($pdo,$viewer,$report);$out[]=$report;}return $out;
}

function living_research_public_topics(PDO $pdo,int $limit=60): array {
    if(!living_research_ready($pdo))return [];$limit=max(1,min(200,$limit));$q=$pdo->query("SELECT rrt.topic_slug,MAX(rrt.topic_label) topic_label,COUNT(DISTINCT rr.id) report_count,MAX(rr.published_at) last_published_at FROM research_report_topics rrt JOIN research_reports rr ON rr.id=rrt.report_id WHERE rr.status='published' AND rr.visibility='public' GROUP BY rrt.topic_slug ORDER BY report_count DESC,last_published_at DESC LIMIT ".$limit);return $q->fetchAll();
}

function living_research_public_topic_reports(PDO $pdo,string $slug,int $limit=50): array {
    if(!living_research_ready($pdo))return [];$slug=living_research_topic_slug($slug);if($slug==='')return [];$limit=max(1,min(100,$limit));$q=$pdo->prepare("SELECT rr.public_id,rr.title,rr.summary,rr.published_at,rv.version_number,u.username,u.display_name,rrt.topic_label FROM research_report_topics rrt JOIN research_reports rr ON rr.id=rrt.report_id JOIN research_report_versions rv ON rv.id=rr.current_version_id JOIN users u ON u.id=rr.created_by_user_id WHERE rrt.topic_slug=? AND rr.status='published' AND rr.visibility='public' ORDER BY rr.published_at DESC LIMIT ".$limit);$q->execute([$slug]);return $q->fetchAll();
}
