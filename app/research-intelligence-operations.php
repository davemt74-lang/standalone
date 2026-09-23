<?php
declare(strict_types=1);

/**
 * Phase 61 — Portfolio Intelligence Operations & Executive Follow-Through.
 * This layer deliberately reuses the existing Research Automation invocation,
 * Research Tasks, Decision Memory, notification, and Phase 59 publishing systems.
 */

function research_intelligence_portfolio_operations_ready(PDO $pdo): bool {
    try{
        foreach(['research_intelligence_portfolio_cycles','research_intelligence_portfolio_subscriptions','research_intelligence_briefing_receipts','research_intelligence_portfolio_decision_links','research_intelligence_portfolio_feedback'] as $table)if(!installer_table_exists($pdo,$table))return false;
        return research_intelligence_portfolios_ready($pdo)&&research_tasks_ready($pdo)&&research_outcomes_ready($pdo);
    }catch(Throwable $e){return false;}
}

function research_intelligence_portfolio_owner(PDO $pdo,array $portfolio): ?array {
    $q=$pdo->prepare("SELECT * FROM users WHERE id=? AND status='active' LIMIT 1");$q->execute([(int)$portfolio['owner_user_id']]);return $q->fetch()?:null;
}

function research_intelligence_portfolio_schedule_next(array $portfolio,?DateTimeImmutable $fromUtc=null): ?string {
    $cadence=(string)($portfolio['briefing_cadence']??'manual');if($cadence==='manual')return null;
    $utc=new DateTimeZone('UTC');$zone=research_automation_timezone((string)($portfolio['timezone_name']??'UTC'));$from=($fromUtc?:new DateTimeImmutable('now',$utc))->setTimezone($zone);
    $time=research_automation_time((string)($portfolio['briefing_time_local']??'09:00:00'));[$hour,$minute,$second]=array_map('intval',explode(':',$time));
    if($cadence==='weekly'){
        $weekday=max(0,min(6,(int)($portfolio['briefing_weekday']??1)));$delta=($weekday-(int)$from->format('w')+7)%7;$candidate=$from->modify('+'.$delta.' days')->setTime($hour,$minute,$second);if($candidate<=$from)$candidate=$candidate->modify('+7 days');
    }elseif(in_array($cadence,['monthly','quarterly'],true)){
        $day=max(1,min(28,(int)($portfolio['briefing_day_of_month']??1)));$candidate=$from->setDate((int)$from->format('Y'),(int)$from->format('n'),$day)->setTime($hour,$minute,$second);
        if($candidate<=$from)$candidate=$candidate->modify('first day of next month')->setDate((int)$candidate->modify('first day of next month')->format('Y'),(int)$candidate->modify('first day of next month')->format('n'),$day)->setTime($hour,$minute,$second);
        if($cadence==='quarterly'&&$candidate<=$from->modify('+2 months')){$base=$from->modify('first day of +3 months');$candidate=$base->setDate((int)$base->format('Y'),(int)$base->format('n'),$day)->setTime($hour,$minute,$second);}
    }else return null;
    return $candidate->setTimezone($utc)->format('Y-m-d H:i:s');
}

function research_intelligence_portfolio_refresh_schedule(PDO $pdo,array $portfolio,bool $force=false): ?string {
    if(!research_intelligence_portfolio_operations_ready($pdo))return null;
    $current=trim((string)($portfolio['next_cycle_at']??''));if(!$force&&$current!=='')return $current;
    $next=research_intelligence_portfolio_schedule_next($portfolio);$pdo->prepare('UPDATE research_intelligence_portfolios SET next_cycle_at=?,updated_at=NOW() WHERE id=?')->execute([$next,(int)$portfolio['id']]);return $next;
}

function research_intelligence_portfolio_material_payload(array $aggregate,string $threshold='important'): array {
    if(!in_array($threshold,['any','important','high'],true))$threshold='important';
    $allow=function(array $row)use($threshold): bool {$importance=(string)($row['importance']??'important');return $threshold==='any'||($threshold==='important'&&in_array($importance,['important','high'],true))||($threshold==='high'&&$importance==='high');};
    $risks=array_values(array_filter((array)($aggregate['risks']??[]),$allow));$opportunities=array_values(array_filter((array)($aggregate['opportunities']??[]),$allow));
    $cross=[];foreach((array)($aggregate['cross_program']??[]) as $row){$items=array_values(array_filter((array)($row['items']??[]),$allow));if($items||$threshold!=='high')$cross[]=['key'=>$row['key']??'','kind'=>$row['kind']??'','program_count'=>(int)($row['program_count']??0),'program_ids'=>$row['program_ids']??[],'items'=>$items];}
    $summary=(array)($aggregate['summary']??[]);$stableSummary=['programs'=>(int)($summary['programs']??0),'active'=>(int)($summary['active']??0),'paused'=>(int)($summary['paused']??0),'failed_runs_30d'=>(int)($summary['failed_runs_30d']??0),'high_changes'=>(int)($summary['high_changes']??0),'stale_sources'=>(int)($summary['stale_sources']??0)];
    return ['summary'=>$stableSummary,'trends'=>$aggregate['trends']??[],'risks'=>$risks,'opportunities'=>$opportunities,'cross_program'=>$cross];
}

function research_intelligence_portfolio_material_hash(array $aggregate,string $threshold='important'): string {
    return hash('sha256',json_encode(research_intelligence_portfolio_material_payload($aggregate,$threshold),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

function research_intelligence_portfolio_cycle_history(PDO $pdo,array $portfolio,int $limit=30): array {
    if(!research_intelligence_portfolio_operations_ready($pdo))return [];$limit=max(1,min(100,$limit));$q=$pdo->prepare("SELECT c.*,s.public_id snapshot_public_id,b.public_id briefing_public_id,b.title briefing_title FROM research_intelligence_portfolio_cycles c LEFT JOIN research_intelligence_portfolio_snapshots s ON s.id=c.snapshot_id LEFT JOIN research_executive_briefings b ON b.id=c.briefing_id WHERE c.portfolio_id=? ORDER BY c.id DESC LIMIT ".$limit);$q->execute([(int)$portfolio['id']]);return $q->fetchAll()?:[];
}

function research_intelligence_portfolio_claim_due_cycle(PDO $pdo,string $portfolioPublic): ?array {
    $pdo->beginTransaction();try{
        $q=$pdo->prepare("SELECT * FROM research_intelligence_portfolios WHERE public_id=? AND status='active' AND briefing_cadence<>'manual' AND next_cycle_at IS NOT NULL AND next_cycle_at<=NOW() LIMIT 1 FOR UPDATE");$q->execute([$portfolioPublic]);$p=$q->fetch();if(!$p){$pdo->commit();return null;}
        $scheduled=(string)$p['next_cycle_at'];$dedupe=hash('sha256','portfolio-cycle|'.$p['id'].'|'.$scheduled);$q=$pdo->prepare('SELECT * FROM research_intelligence_portfolio_cycles WHERE dedupe_key=? LIMIT 1');$q->execute([$dedupe]);$cycle=$q->fetch();
        if($cycle){
            if(in_array((string)$cycle['status'],['completed','skipped'],true)){$next=research_intelligence_portfolio_schedule_next($p,(new DateTimeImmutable($scheduled,new DateTimeZone('UTC')))->modify('+1 second'));$pdo->prepare('UPDATE research_intelligence_portfolios SET next_cycle_at=?,last_cycle_at=COALESCE(last_cycle_at,?),updated_at=NOW() WHERE id=?')->execute([$next,$cycle['completed_at']?:$scheduled,(int)$p['id']]);$pdo->commit();return null;}
            $stale=$cycle['status']==='processing'&&strtotime((string)$cycle['updated_at'])<time()-1800;if($cycle['status']==='processing'&&!$stale){$pdo->commit();return null;}
            if((int)$cycle['attempts']>=3){$next=research_intelligence_portfolio_schedule_next($p,(new DateTimeImmutable($scheduled,new DateTimeZone('UTC')))->modify('+1 second'));$pdo->prepare('UPDATE research_intelligence_portfolios SET next_cycle_at=?,last_cycle_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$next,(int)$p['id']]);$pdo->commit();return null;}
            $pdo->prepare("UPDATE research_intelligence_portfolio_cycles SET status='processing',attempts=attempts+1,trigger_type='recovery',started_at=NOW(),detail=NULL,updated_at=NOW() WHERE id=?")->execute([(int)$cycle['id']]);
        }else{
            $pdo->prepare("INSERT INTO research_intelligence_portfolio_cycles(public_id,portfolio_id,scheduled_for,trigger_type,status,dedupe_key) VALUES(?,?,?,'schedule','processing',?)")->execute([ulid_like(),(int)$p['id'],$scheduled,$dedupe]);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM research_intelligence_portfolio_cycles WHERE id=?');$q->execute([$id]);$cycle=$q->fetch();
        }
        $pdo->commit();return ['portfolio'=>$p,'cycle'=>$cycle,'scheduled_for'=>$scheduled];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function research_intelligence_portfolio_subscription(PDO $pdo,array $viewer,string $portfolioPublic): ?array {
    if(!research_intelligence_portfolio_operations_ready($pdo))return null;$p=research_intelligence_portfolio_access($pdo,$viewer,$portfolioPublic);if(!$p)return null;$q=$pdo->prepare('SELECT * FROM research_intelligence_portfolio_subscriptions WHERE portfolio_id=? AND user_id=? LIMIT 1');$q->execute([(int)$p['id'],(int)$viewer['id']]);return $q->fetch()?:null;
}

function research_intelligence_portfolio_subscription_set(PDO $pdo,array $viewer,string $portfolioPublic,array $input): array {
    $p=research_intelligence_portfolio_access($pdo,$viewer,$portfolioPublic);if(!$p)throw new RuntimeException('Portfolio not found.');
    if(!research_intelligence_portfolio_operations_ready($pdo))throw new RuntimeException('Portfolio subscriptions require the latest database upgrade.');
    $status=(string)($input['status']??'active');if(!in_array($status,['active','paused'],true))$status='active';$cadence=(string)($input['cadence']??'inherit');if(!in_array($cadence,['inherit','every_briefing','weekly','monthly','quarterly'],true))$cadence='inherit';$delivery=(string)($input['delivery_preference']??'both');if(!in_array($delivery,['draft_ready','published_only','both'],true))$delivery='both';
    $pdo->prepare("INSERT INTO research_intelligence_portfolio_subscriptions(portfolio_id,user_id,status,cadence,delivery_preference) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),cadence=VALUES(cadence),delivery_preference=VALUES(delivery_preference),updated_at=NOW()")->execute([(int)$p['id'],(int)$viewer['id'],$status,$cadence,$delivery]);
    research_intelligence_portfolio_event($pdo,(int)$p['id'],'subscription_updated','user',(int)$viewer['id'],['status'=>$status,'cadence'=>$cadence,'delivery_preference'=>$delivery]);return research_intelligence_portfolio_subscription($pdo,$viewer,$portfolioPublic)??[];
}

function research_intelligence_portfolio_subscriber_rows(PDO $pdo,array $portfolio,string $delivery='draft'): array {
    if(!research_intelligence_portfolio_operations_ready($pdo))return [];$clause=$delivery==='published'?"delivery_preference IN ('published_only','both')":"delivery_preference IN ('draft_ready','both')";
    $q=$pdo->prepare("SELECT s.*,u.public_id user_public_id,u.display_name,u.username,u.status user_status FROM research_intelligence_portfolio_subscriptions s JOIN users u ON u.id=s.user_id WHERE s.portfolio_id=? AND s.status='active' AND u.status='active' AND $clause ORDER BY u.display_name,u.username");$q->execute([(int)$portfolio['id']]);return $q->fetchAll()?:[];
}

function research_intelligence_portfolio_subscription_due(array $sub,string $when): bool {
    $last=trim((string)($sub['last_notified_at']??''));if($last==='')return true;$cadence=(string)$sub['cadence'];if(in_array($cadence,['inherit','every_briefing'],true))return true;$days=match($cadence){'weekly'=>7,'monthly'=>30,'quarterly'=>90,default=>0};return $days===0||strtotime($last)<=strtotime($when)-($days*86400);
}

function research_intelligence_portfolio_notify_briefing(PDO $pdo,array $viewer,array $portfolio,array $briefing,string $stage='draft'): array {
    $sent=0;$skipped=0;$when=date('Y-m-d H:i:s');foreach(research_intelligence_portfolio_subscriber_rows($pdo,$portfolio,$stage==='published'?'published':'draft') as $sub){
        $q=$pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');$q->execute([(int)$sub['user_id']]);$recipient=$q->fetch();if(!$recipient||!research_intelligence_portfolio_access($pdo,$recipient,(string)$portfolio['public_id'])){$skipped++;continue;}
        if(!research_intelligence_portfolio_subscription_due($sub,$when)){$skipped++;continue;}$type=$stage==='published'?'research_portfolio_briefing_published':'research_portfolio_briefing_ready';$dedupe='portfolio-briefing:'.$stage.':'.$briefing['public_id'].':'.$sub['user_id'];$body=($stage==='published'?'Published Executive Briefing: ':'Executive Briefing ready for review: ').$briefing['title'];
        notification_create($pdo,(int)$sub['user_id'],(int)$viewer['id'],$type,'research_intelligence_portfolio',(string)$portfolio['public_id'],$body,['allow_self'=>true,'category'=>'research','dedupe_key'=>$dedupe,'group_key'=>'portfolio-briefing:'.$portfolio['public_id'],'context'=>['portfolio_public_id'=>$portfolio['public_id'],'briefing_public_id'=>$briefing['public_id'],'stage'=>$stage]]);
        $n=$pdo->prepare('SELECT public_id,read_at FROM notifications WHERE user_id=? AND dedupe_key=? LIMIT 1');$n->execute([(int)$sub['user_id'],$dedupe]);$notification=$n->fetch()?:[];
        $pdo->prepare("INSERT INTO research_intelligence_briefing_receipts(briefing_id,user_id,notification_public_id,delivery_state,notified_at,read_at) VALUES(?,?,?,'notified',NOW(),?) ON DUPLICATE KEY UPDATE notification_public_id=COALESCE(VALUES(notification_public_id),notification_public_id),notified_at=COALESCE(notified_at,NOW()),read_at=COALESCE(read_at,VALUES(read_at)),updated_at=NOW()")->execute([(int)$briefing['id'],(int)$sub['user_id'],$notification['public_id']??null,$notification['read_at']??null]);
        $pdo->prepare('UPDATE research_intelligence_portfolio_subscriptions SET last_notified_at=NOW(),updated_at=NOW() WHERE portfolio_id=? AND user_id=?')->execute([(int)$portfolio['id'],(int)$sub['user_id']]);$sent++;
    }return ['sent'=>$sent,'skipped'=>$skipped];
}

function research_intelligence_portfolio_receipts(PDO $pdo,array $viewer,string $briefingPublic): array {
    $brief=research_intelligence_portfolio_briefing_access($pdo,$viewer,$briefingPublic);if(!$brief)return [];$q=$pdo->prepare("SELECT r.*,u.display_name,u.username,n.read_at notification_read_at FROM research_intelligence_briefing_receipts r JOIN users u ON u.id=r.user_id LEFT JOIN notifications n ON n.public_id=r.notification_public_id AND n.user_id=r.user_id WHERE r.briefing_id=? ORDER BY u.display_name,u.username");$q->execute([(int)$brief['id']]);$rows=$q->fetchAll()?:[];
    foreach($rows as $row)if(!empty($row['notification_read_at'])&&empty($row['read_at']))$pdo->prepare("UPDATE research_intelligence_briefing_receipts SET delivery_state=CASE WHEN delivery_state='acknowledged' THEN delivery_state ELSE 'read' END,read_at=?,updated_at=NOW() WHERE briefing_id=? AND user_id=?")->execute([$row['notification_read_at'],(int)$brief['id'],(int)$row['user_id']]);
    $q->execute([(int)$brief['id']]);return $q->fetchAll()?:[];
}

function research_intelligence_portfolio_acknowledge(PDO $pdo,array $viewer,string $briefingPublic): array {
    $brief=research_intelligence_portfolio_briefing_access($pdo,$viewer,$briefingPublic);if(!$brief)throw new RuntimeException('Executive Briefing not found.');$q=$pdo->prepare("INSERT INTO research_intelligence_briefing_receipts(briefing_id,user_id,delivery_state,acknowledged_at) VALUES(?,?,'acknowledged',NOW()) ON DUPLICATE KEY UPDATE delivery_state='acknowledged',acknowledged_at=NOW(),read_at=COALESCE(read_at,NOW()),updated_at=NOW()");$q->execute([(int)$brief['id'],(int)$viewer['id']]);return ['acknowledged'=>true,'briefing_id'=>$briefingPublic];
}

function research_intelligence_portfolio_publication_recipient_ids(PDO $pdo,array $portfolio): array {
    $ids=[];foreach(research_intelligence_portfolio_subscriber_rows($pdo,$portfolio,'published') as $sub)$ids[]=(int)$sub['user_id'];return array_values(array_unique($ids));
}

function research_intelligence_portfolio_publication_distributed(PDO $pdo,array $viewer,array $workflow,array $publish,array $distribution): void {
    if(!research_intelligence_portfolio_operations_ready($pdo))return;$q=$pdo->prepare("SELECT b.*,p.public_id portfolio_public_id FROM research_executive_briefings b JOIN research_intelligence_portfolios p ON p.id=b.portfolio_id WHERE b.publication_workflow_id=? LIMIT 1");$q->execute([(int)$workflow['id']]);$brief=$q->fetch();if(!$brief)return;$pdo->prepare("UPDATE research_executive_briefings SET status='published',updated_at=NOW() WHERE id=?")->execute([(int)$brief['id']]);$portfolio=research_intelligence_portfolio_access($pdo,$viewer,(string)$brief['portfolio_public_id']);if($portfolio)research_intelligence_portfolio_notify_briefing($pdo,$viewer,$portfolio,$brief,'published');
}

function research_intelligence_portfolio_insight(PDO $pdo,array $portfolio,string $publicId): ?array {
    $q=$pdo->prepare('SELECT * FROM research_intelligence_insights WHERE portfolio_id=? AND public_id=? LIMIT 1');$q->execute([(int)$portfolio['id'],trim($publicId)]);return $q->fetch()?:null;
}

function research_intelligence_portfolio_record_decision(PDO $pdo,array $viewer,string $portfolioPublic,array $input): array {
    $p=research_intelligence_portfolio_access($pdo,$viewer,$portfolioPublic);if(!$p||!research_intelligence_portfolio_can_write($p))throw new RuntimeException('Portfolio decision access is unavailable.');if(!research_intelligence_portfolio_operations_ready($pdo))throw new RuntimeException('Portfolio follow-through requires the latest database upgrade.');
    $title=mb_substr(trim((string)($input['title']??'')),0,255);if($title==='')throw new InvalidArgumentException('Decision title is required.');$summary=mb_substr(trim((string)($input['summary']??'')),0,1200);$note=mb_substr(trim((string)($input['note']??'')),0,8000);$decision=(string)($input['decision_type']??'recorded');if(!isset(research_outcome_decision_labels()[$decision]))$decision='recorded';
    $insight=null;$briefing=null;if(trim((string)($input['insight_id']??''))!==''){$insight=research_intelligence_portfolio_insight($pdo,$p,(string)$input['insight_id']);if(!$insight)throw new RuntimeException('Portfolio insight is unavailable.');}
    if(trim((string)($input['briefing_id']??''))!==''){$briefing=research_intelligence_portfolio_briefing_access($pdo,$viewer,(string)$input['briefing_id']);if(!$briefing||(int)$briefing['portfolio_id']!==(int)$p['id'])throw new RuntimeException('Executive Briefing is unavailable.');}
    $programs=research_intelligence_portfolio_programs($pdo,$viewer,$p);if(!$programs)throw new RuntimeException('Portfolio has no Research Programs.');$anchor=$programs[0];foreach($programs as $program)if((int)$program['id']===(int)($p['anchor_program_id']??0)){$anchor=$program;break;}$project=project_access($pdo,(int)$viewer['id'],(string)$anchor['project_public_id']);if(!$project||!project_can_write($project))throw new RuntimeException('Anchor Research workspace is read only.');
    $task=null;if(!empty($input['create_follow_up'])){$task=research_task_create_for_project($pdo,$viewer,$project,['title'=>mb_substr(trim((string)($input['follow_up_title']??('Follow up: '.$title))),0,255),'description'=>$note!==''?$note:($summary!==''?$summary:'Executive Portfolio decision follow-through.'),'task_type'=>'follow_up','priority'=>(string)($input['priority']??'high'),'due_at'=>(string)($input['due_at']??'')],false);}
    $refs=[['type'=>'portfolio','public_id'=>$p['public_id'],'role'=>'context']];if($insight)$refs[]=['type'=>'portfolio_insight','public_id'=>$insight['public_id'],'role'=>'source'];if($briefing)$refs[]=['type'=>'executive_briefing','public_id'=>$briefing['public_id'],'role'=>'source'];if($task)$refs[]=['type'=>'task','public_id'=>$task['public_id'],'role'=>'result'];
    $outcome=research_outcome_record($pdo,$viewer,['event_type'=>'portfolio_decision','decision_type'=>$decision,'source_type'=>'research_intelligence_portfolio','source_public_id'=>$p['public_id'],'project_public_id'=>$project['public_id'],'object_type'=>$insight?'portfolio_insight':($briefing?'executive_briefing':'portfolio'),'object_public_id'=>$insight['public_id']??$briefing['public_id']??$p['public_id'],'result_type'=>$task?'task':'','result_public_id'=>$task['public_id']??'','title'=>$title,'summary'=>$summary,'note'=>$note,'metadata'=>['portfolio_public_id'=>$p['public_id'],'briefing_public_id'=>$briefing['public_id']??null,'insight_public_id'=>$insight['public_id']??null],'refs'=>$refs,'is_manual'=>true,'occurred_at'=>date('Y-m-d H:i:s'),'dedupe_key'=>'portfolio-decision:'.$p['public_id'].':'.ulid_like()]);
    if(!$outcome)throw new RuntimeException('Decision Memory did not record the Portfolio decision.');$pdo->prepare("INSERT INTO research_intelligence_portfolio_decision_links(public_id,portfolio_id,outcome_id,insight_id,briefing_id,task_id,created_by_user_id) VALUES(?,?,?,?,?,?,?)")->execute([ulid_like(),(int)$p['id'],(int)$outcome['id'],$insight['id']??null,$briefing['id']??null,$task['id']??null,(int)$viewer['id']]);
    if($task)research_outcome_feedback_set($pdo,$viewer,(string)$outcome['public_id'],null,'follow_up','Follow-through task created from Portfolio decision.');
    research_intelligence_portfolio_event($pdo,(int)$p['id'],'decision_recorded','user',(int)$viewer['id'],['outcome_id'=>$outcome['public_id'],'task_id'=>$task['public_id']??null]);return ['outcome'=>$outcome,'task'=>$task,'portfolio'=>$p];
}

function research_intelligence_portfolio_feedback_set(PDO $pdo,array $viewer,string $portfolioPublic,array $input): array {
    $p=research_intelligence_portfolio_access($pdo,$viewer,$portfolioPublic);if(!$p)throw new RuntimeException('Portfolio not found.');$type=(string)($input['feedback_type']??'useful');if(!in_array($type,['useful','irrelevant','resolved','escalated','acted_on'],true))throw new InvalidArgumentException('Invalid Portfolio feedback.');
    $insight=null;$briefing=null;$insightPublic=trim((string)($input['insight_id']??''));$briefPublic=trim((string)($input['briefing_id']??''));if($insightPublic!==''&&$briefPublic!=='')throw new InvalidArgumentException('Feedback must target either an insight or a briefing, not both.');
    if($insightPublic!==''){$insight=research_intelligence_portfolio_insight($pdo,$p,$insightPublic);if(!$insight)throw new RuntimeException('Portfolio insight is unavailable.');}if($briefPublic!==''){$briefing=research_intelligence_portfolio_briefing_access($pdo,$viewer,$briefPublic);if(!$briefing||(int)$briefing['portfolio_id']!==(int)$p['id'])throw new RuntimeException('Executive Briefing is unavailable.');}
    $note=mb_substr(trim((string)($input['note']??'')),0,2000);$target=$insightPublic!==''?'insight:'.$insightPublic:($briefPublic!==''?'briefing:'.$briefPublic:'portfolio:'.$p['public_id']);$fingerprint=hash('sha256',$target);
    $public=ulid_like();$pdo->prepare("INSERT INTO research_intelligence_portfolio_feedback(public_id,portfolio_id,user_id,insight_id,briefing_id,feedback_type,note,fingerprint) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE feedback_type=VALUES(feedback_type),note=VALUES(note),updated_at=NOW()")->execute([$public,(int)$p['id'],(int)$viewer['id'],$insight['id']??null,$briefing['id']??null,$type,$note?:null,$fingerprint]);$q=$pdo->prepare('SELECT * FROM research_intelligence_portfolio_feedback WHERE portfolio_id=? AND user_id=? AND fingerprint=? LIMIT 1');$q->execute([(int)$p['id'],(int)$viewer['id'],$fingerprint]);$row=$q->fetch()?:[];
    research_intelligence_portfolio_event($pdo,(int)$p['id'],'feedback_recorded','user',(int)$viewer['id'],['feedback_type'=>$type,'target'=>$target]);return $row;
}

function research_intelligence_portfolio_feedback_rows(PDO $pdo,array $portfolio,int $limit=60): array {
    if(!research_intelligence_portfolio_operations_ready($pdo))return [];$limit=max(1,min(200,$limit));$q=$pdo->prepare("SELECT f.*,u.display_name,u.username,i.public_id insight_public_id,i.title insight_title,b.public_id briefing_public_id,b.title briefing_title FROM research_intelligence_portfolio_feedback f JOIN users u ON u.id=f.user_id LEFT JOIN research_intelligence_insights i ON i.id=f.insight_id LEFT JOIN research_executive_briefings b ON b.id=f.briefing_id WHERE f.portfolio_id=? ORDER BY f.updated_at DESC LIMIT ".$limit);$q->execute([(int)$portfolio['id']]);return $q->fetchAll()?:[];
}

function research_intelligence_portfolio_decision_rows(PDO $pdo,array $viewer,array $portfolio,int $limit=60): array {
    if(!research_intelligence_portfolio_operations_ready($pdo))return [];$limit=max(1,min(200,$limit));$q=$pdo->prepare("SELECT l.public_id link_public_id,o.public_id outcome_public_id,o.title,o.summary,o.decision_type,o.occurred_at,f.follow_up_state,f.usefulness,t.public_id task_public_id,t.title task_title,t.status task_status,t.priority task_priority,t.due_at,i.public_id insight_public_id,b.public_id briefing_public_id FROM research_intelligence_portfolio_decision_links l JOIN research_outcome_events o ON o.id=l.outcome_id LEFT JOIN research_outcome_feedback f ON f.outcome_id=o.id AND f.user_id=o.user_id LEFT JOIN research_tasks t ON t.id=l.task_id LEFT JOIN research_intelligence_insights i ON i.id=l.insight_id LEFT JOIN research_executive_briefings b ON b.id=l.briefing_id WHERE l.portfolio_id=? ORDER BY o.occurred_at DESC LIMIT ".$limit);$q->execute([(int)$portfolio['id']]);$out=[];foreach($q->fetchAll() as $row){if(research_outcome_access($pdo,$viewer,(string)$row['outcome_public_id']))$out[]=$row;}return $out;
}

function research_intelligence_portfolio_process_due(PDO $pdo,int $limit=10): array {
    if(!research_intelligence_portfolio_operations_ready($pdo))return ['claimed'=>0,'completed'=>0,'skipped'=>0,'failed'=>0,'briefings'=>0];$limit=max(1,min(100,$limit));$q=$pdo->query("SELECT public_id FROM research_intelligence_portfolios WHERE status='active' AND briefing_cadence<>'manual' AND next_cycle_at IS NOT NULL AND next_cycle_at<=NOW() ORDER BY next_cycle_at,id LIMIT ".$limit);$ids=$q->fetchAll(PDO::FETCH_COLUMN)?:[];$stats=['claimed'=>0,'completed'=>0,'skipped'=>0,'failed'=>0,'briefings'=>0];
    foreach($ids as $public){$claim=research_intelligence_portfolio_claim_due_cycle($pdo,(string)$public);if(!$claim)continue;$stats['claimed']++;$p=$claim['portfolio'];$cycle=$claim['cycle'];$viewer=research_intelligence_portfolio_owner($pdo,$p);
        try{if(!$viewer)throw new RuntimeException('Portfolio owner is unavailable.');$access=research_intelligence_portfolio_access($pdo,$viewer,(string)$p['public_id']);if(!$access)throw new RuntimeException('Portfolio owner no longer has Portfolio access.');
            $aggregate=research_intelligence_portfolio_aggregate($pdo,$viewer,$access,30);$material=research_intelligence_portfolio_material_hash($aggregate,(string)($p['materiality_threshold']??'important'));$prevQ=$pdo->prepare("SELECT material_hash FROM research_intelligence_portfolio_cycles WHERE portfolio_id=? AND id<>? AND status IN ('completed','skipped') AND material_hash IS NOT NULL ORDER BY id DESC LIMIT 1");$prevQ->execute([(int)$p['id'],(int)$cycle['id']]);$previous=(string)($prevQ->fetchColumn()?:'');$changed=$previous===''||!hash_equals($previous,$material);
            $snapshot=research_intelligence_portfolio_snapshot($pdo,$viewer,(string)$p['public_id'],30,'system');$briefing=null;$policy=(string)($p['briefing_policy']??'material_only');if($policy==='always'||$changed){$briefing=research_intelligence_portfolio_create_briefing($pdo,$viewer,(string)$p['public_id'],['snapshot_public_id'=>$snapshot['public_id'],'window_days'=>30],false);$stats['briefings']++;$briefRow=research_intelligence_portfolio_briefing_access($pdo,$viewer,(string)$briefing['public_id']);if($briefRow)research_intelligence_portfolio_notify_briefing($pdo,$viewer,$access,$briefRow,'draft');}
            $status=$briefing?'completed':'skipped';$detail=$briefing?'Executive Briefing draft created.':'No material Portfolio change at the configured threshold.';$pdo->prepare("UPDATE research_intelligence_portfolio_cycles SET status=?,material_hash=?,previous_material_hash=?,material_changed=?,snapshot_id=?,briefing_id=?,detail=?,completed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$status,$material,$previous?:null,$changed?1:0,(int)$snapshot['id'],$briefing?(int)(research_intelligence_portfolio_briefing_access($pdo,$viewer,(string)$briefing['public_id'])['id']??0):null,$detail,(int)$cycle['id']]);$next=research_intelligence_portfolio_schedule_next($p,(new DateTimeImmutable((string)$claim['scheduled_for'],new DateTimeZone('UTC')))->modify('+1 second'));$pdo->prepare('UPDATE research_intelligence_portfolios SET next_cycle_at=?,last_cycle_at=NOW(),last_material_hash=?,updated_at=NOW() WHERE id=?')->execute([$next,$material,(int)$p['id']]);$stats[$status]++;
            research_intelligence_portfolio_event($pdo,(int)$p['id'],'cycle_'.$status,'system',null,['cycle_id'=>$cycle['public_id'],'material_changed'=>$changed,'briefing_id'=>$briefing['public_id']??null]);
        }catch(Throwable $e){$stats['failed']++;$pdo->prepare("UPDATE research_intelligence_portfolio_cycles SET status='failed',detail=?,completed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([mb_substr($e->getMessage(),0,1000),(int)$cycle['id']]);if((int)$cycle['attempts']>=3){$next=research_intelligence_portfolio_schedule_next($p,(new DateTimeImmutable((string)$claim['scheduled_for'],new DateTimeZone('UTC')))->modify('+1 second'));$pdo->prepare('UPDATE research_intelligence_portfolios SET next_cycle_at=?,last_cycle_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$next,(int)$p['id']]);if($viewer)notification_create($pdo,(int)$viewer['id'],null,'research_portfolio_cycle_failed','research_intelligence_portfolio',(string)$p['public_id'],'Portfolio intelligence cycle failed repeatedly: '.$p['title'],['allow_self'=>true,'category'=>'research','dedupe_key'=>'portfolio-cycle-failed:'.$cycle['public_id'],'context'=>['portfolio_public_id'=>$p['public_id'],'cycle_public_id'=>$cycle['public_id']]]);} }
    }return $stats;
}

function research_intelligence_portfolio_operations_detail(PDO $pdo,array $viewer,array $portfolio): array {
    if(!research_intelligence_portfolio_operations_ready($pdo))return ['ready'=>false];$sub=research_intelligence_portfolio_subscription($pdo,$viewer,(string)$portfolio['public_id']);return ['ready'=>true,'subscription'=>$sub,'cycles'=>research_intelligence_portfolio_cycle_history($pdo,$portfolio,20),'decisions'=>research_intelligence_portfolio_decision_rows($pdo,$viewer,$portfolio,50),'feedback'=>research_intelligence_portfolio_feedback_rows($pdo,$portfolio,50)];
}

function research_intelligence_organization_command_center(PDO $pdo,array $viewer): array {
    $dashboard=research_intelligence_portfolio_dashboard($pdo,$viewer);$needs=[];$changed=[];$opportunities=[];$briefings=[];$decisions=[];$themeMap=[];
    foreach($dashboard['portfolios'] as $row){$p=research_intelligence_portfolio_detail($pdo,$viewer,(string)$row['public_id']);if(!$p)continue;$a=$p['aggregate'];$tensions=count(array_filter($a['cross_program'],fn($x)=>($x['kind']??'')==='cross_program_tension'));if(count($a['risks'])||$tensions||!empty($p['briefing_due']))$needs[]=['portfolio_id'=>$p['public_id'],'title'=>$p['title'],'risks'=>count($a['risks']),'tensions'=>$tensions,'briefing_due'=>(bool)$p['briefing_due']];
        foreach(array_slice($a['opportunities'],0,5) as $o)$opportunities[]=['portfolio_id'=>$p['public_id'],'portfolio_title'=>$p['title']]+$o;foreach($a['cross_program'] as $x){$key=(string)($x['key']??'');if($key==='')continue;$themeMap[$key]['portfolios'][$p['public_id']]=$p['title'];$themeMap[$key]['kinds'][(string)$x['kind']]=true;}
        if(research_intelligence_portfolio_operations_ready($pdo)){$cycles=research_intelligence_portfolio_cycle_history($pdo,$p,1);if($cycles&&($cycles[0]['material_changed']??0))$changed[]=['portfolio_id'=>$p['public_id'],'title'=>$p['title'],'cycle'=>$cycles[0]];foreach(research_intelligence_portfolio_decision_rows($pdo,$viewer,$p,20) as $d)if(!empty($d['task_public_id'])&&!in_array((string)$d['task_status'],['complete','done','archived'],true))$decisions[]=['portfolio_id'=>$p['public_id'],'portfolio_title'=>$p['title']]+$d;}
        foreach($p['briefings'] as $b)if(($b['status']??'')==='in_review'&&($b['publication_status']??'')!=='published')$briefings[]=['portfolio_id'=>$p['public_id'],'portfolio_title'=>$p['title']]+$b;
    }
    $themes=[];foreach($themeMap as $key=>$g)if(count($g['portfolios'])>=2)$themes[]=['key'=>$key,'portfolio_count'=>count($g['portfolios']),'portfolios'=>$g['portfolios'],'kinds'=>array_keys($g['kinds'])];usort($themes,fn($a,$b)=>$b['portfolio_count']<=>$a['portfolio_count']);
    return ['summary'=>$dashboard['summary'],'needs_attention'=>$needs,'new_since_last_briefing'=>$changed,'decisions_awaiting_follow_through'=>$decisions,'emerging_opportunities'=>array_slice($opportunities,0,30),'cross_portfolio_themes'=>array_slice($themes,0,30),'briefings_awaiting_review'=>$briefings,'generated_at'=>date('Y-m-d H:i:s')];
}

function research_intelligence_portfolio_operations_cognitive_observations(PDO $pdo,array $viewer,array &$items,int $limit=20): void {
    if(!research_intelligence_portfolio_operations_ready($pdo))return;$center=research_intelligence_organization_command_center($pdo,$viewer);$added=0;
    foreach($center['decisions_awaiting_follow_through'] as $d){if($added++>=$limit)break;cognitive_feed_add($items,['key'=>cognitive_feed_key('portfolio_follow_through','research_task',(string)$d['task_public_id'],(string)($d['occurred_at']??'')),'type'=>'portfolio_follow_through','section'=>'needs_attention','priority'=>in_array((string)$d['task_priority'],['urgent','high'],true)?'high':'medium','created_at'=>(string)$d['occurred_at'],'title'=>'Executive follow-through · '.$d['title'],'body'=>$d['portfolio_title'].' · '.($d['task_title']??'Follow-up task'),'meta'=>['task_status'=>$d['task_status'],'portfolio'=>$d['portfolio_title']],'actions'=>[cognitive_feed_action_link('Open Portfolio','/research-intelligence-portfolios.php?portfolio='.rawurlencode((string)$d['portfolio_id'])),cognitive_feed_action_link('Open Task','/research-tasks.php?task='.rawurlencode((string)$d['task_public_id']))]]);}
    foreach($center['briefings_awaiting_review'] as $b){if($added++>=$limit)break;cognitive_feed_add($items,['key'=>cognitive_feed_key('portfolio_briefing_review','executive_briefing',(string)$b['public_id'],(string)$b['updated_at']),'type'=>'portfolio_briefing_review','section'=>'needs_attention','priority'=>'high','created_at'=>(string)$b['updated_at'],'title'=>'Executive Briefing awaiting review','body'=>$b['portfolio_title'].' · '.$b['title'],'meta'=>['portfolio'=>$b['portfolio_title']],'actions'=>[cognitive_feed_action_link('Open Portfolio','/research-intelligence-portfolios.php?portfolio='.rawurlencode((string)$b['portfolio_id']))]]);}
}
