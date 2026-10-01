<?php
declare(strict_types=1);

function sponsored_project_compensation_ready(PDO $pdo): bool {
    try{return sponsored_projects_ready($pdo)&&installer_table_exists($pdo,'sponsored_research_project_compensations');}catch(Throwable $e){return false;}
}
function sponsored_project_compensation_for_assignment(PDO $pdo,int $assignmentId): ?array {
    if(!sponsored_project_compensation_ready($pdo))return null;
    $q=$pdo->prepare("SELECT pc.*,c.public_id campaign_public_id,c.title campaign_title,a.public_id assignment_public_id,
      ra.public_id agent_public_id,ra.name agent_name,u.display_name researcher_name,u.username researcher_username,
      s.public_id accepted_submission_public_id
      FROM sponsored_research_project_compensations pc
      JOIN sponsored_research_campaigns c ON c.id=pc.campaign_id
      JOIN sponsored_research_agent_assignments a ON a.id=pc.assignment_id
      JOIN research_agents ra ON ra.id=pc.research_agent_id
      JOIN users u ON u.id=pc.researcher_user_id
      LEFT JOIN sponsored_research_project_submissions s ON s.id=pc.accepted_submission_id
      WHERE pc.assignment_id=? LIMIT 1");
    $q->execute([$assignmentId]);return $q->fetch()?:null;
}
function sponsored_project_compensation_get(PDO $pdo,string $publicId): ?array {
    if(!sponsored_project_compensation_ready($pdo))return null;
    $q=$pdo->prepare('SELECT assignment_id FROM sponsored_research_project_compensations WHERE public_id=? LIMIT 1');$q->execute([trim($publicId)]);
    $assignmentId=(int)($q->fetchColumn()?:0);return $assignmentId>0?sponsored_project_compensation_for_assignment($pdo,$assignmentId):null;
}
function sponsored_project_compensation_ensure(PDO $pdo,array $assignment,array $campaign,?int $actorUserId=null): array {
    $existing=sponsored_project_compensation_for_assignment($pdo,(int)$assignment['id']);if($existing)return $existing;
    $amount=(int)($campaign['researcher_compensation_cents']??0);if($amount<=0)throw new RuntimeException('Sponsored Project compensation must be configured before a researcher can take this project.');
    $currency=sponsored_research_finance_currency((string)$campaign['budget_currency']);
    $public=ulid_like();$pdo->prepare("INSERT INTO sponsored_research_project_compensations(public_id,campaign_id,assignment_id,researcher_user_id,research_agent_id,compensation_model,amount_cents,currency,status,updated_by_user_id)
      VALUES(?,?,?,?,?,'flat_fee',?,?,'pending',?)")
      ->execute([$public,(int)$campaign['id'],(int)$assignment['id'],(int)$assignment['researcher_user_id'],(int)$assignment['research_agent_id'],$amount,$currency,$actorUserId]);
    sponsored_project_event($pdo,(int)$campaign['id'],(int)$assignment['id'],null,$actorUserId,'project_compensation_agreed',['compensation_public_id'=>$public,'amount_cents'=>$amount,'currency'=>$currency,'model'=>'flat_fee']);
    return sponsored_project_compensation_for_assignment($pdo,(int)$assignment['id'])??[];
}
function sponsored_project_compensation_backfill_campaign(PDO $pdo,array $campaign,?int $actorUserId=null): int {
    if((int)($campaign['researcher_compensation_cents']??0)<=0)return 0;
    $q=$pdo->prepare("SELECT a.* FROM sponsored_research_agent_assignments a
      LEFT JOIN sponsored_research_project_compensations pc ON pc.assignment_id=a.id
      WHERE a.campaign_id=? AND pc.id IS NULL AND a.status IN ('active','paused','completed')");
    $q->execute([(int)$campaign['id']]);$count=0;foreach($q->fetchAll()?:[] as $assignment){sponsored_project_compensation_ensure($pdo,$assignment,$campaign,$actorUserId);$count++;}return $count;
}
function sponsored_project_compensation_mark_earned(PDO $pdo,array $submission,array $actor): array {
    $campaign=sponsored_research_campaign_by_public($pdo,(string)$submission['campaign_public_id']);if(!$campaign)throw new RuntimeException('Sponsored Project not found.');
    $q=$pdo->prepare('SELECT * FROM sponsored_research_agent_assignments WHERE id=? LIMIT 1');$q->execute([(int)$submission['assignment_id']);$assignment=$q->fetch();if(!$assignment)throw new RuntimeException('Sponsored Project assignment not found.');
    $comp=sponsored_project_compensation_ensure($pdo,$assignment,$campaign,(int)$actor['id']);
    if(in_array((string)$comp['status'],['earned','approved_for_payment','paid'],true))return $comp;
    if((string)$comp['status']==='voided')throw new RuntimeException('Voided project compensation cannot be earned.');
    $pdo->prepare("UPDATE sponsored_research_project_compensations SET status='earned',accepted_submission_id=?,earned_at=NOW(),updated_by_user_id=? WHERE id=?")
      ->execute([(int)$submission['id'],(int)$actor['id'],(int)$comp['id']]);
    sponsored_project_event($pdo,(int)$submission['campaign_id'],(int)$submission['assignment_id'],(int)$submission['id'],(int)$actor['id'],'project_compensation_earned',['compensation_public_id'=>$comp['public_id'],'amount_cents'=>(int)$comp['amount_cents'],'currency'=>$comp['currency']]);
    if(function_exists('notification_create'))notification_create($pdo,(int)$submission['researcher_user_id'],(int)$actor['id'],'research_sponsored_compensation_earned','sponsored_project_submission',(string)$submission['public_id'],'Your Sponsored Project compensation is now earned.',['category'=>'research','dedupe_key'=>'sponsored-comp-earned:'.$comp['public_id']]);
    return sponsored_project_compensation_for_assignment($pdo,(int)$submission['assignment_id'])??[];
}
function sponsored_project_compensation_admin_transition(PDO $pdo,array $admin,string $compPublicId,string $status,string $paymentReference='',string $note=''): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required.');
    $comp=sponsored_project_compensation_get($pdo,$compPublicId);if(!$comp)throw new RuntimeException('Sponsored Project compensation record not found.');
    $from=(string)$comp['status'];$allowed=[
      'pending'=>['voided'],'earned'=>['approved_for_payment','voided'],'approved_for_payment'=>['paid','voided'],'paid'=>[],'voided'=>[]
    ];if(!in_array($status,$allowed[$from]??[],true))throw new RuntimeException('That compensation status transition is not allowed.');
    $paymentReference=mb_substr(trim($paymentReference),0,190);$note=mb_substr(trim($note),0,12000);
    if($status==='paid'&&$paymentReference==='')throw new InvalidArgumentException('Payment reference is required when marking compensation paid.');
    $sets=["status=?","updated_by_user_id=?","admin_note=?"];$params=[$status,(int)$admin['id'],$note!==''?$note:null];
    if($status==='approved_for_payment')$sets[]='approved_at=NOW()';
    elseif($status==='paid'){$sets[]='paid_at=NOW()';$sets[]='payment_reference=?';$params[]=$paymentReference;}
    elseif($status==='voided')$sets[]='voided_at=NOW()';
    $params[]=(int)$comp['id'];$pdo->prepare('UPDATE sponsored_research_project_compensations SET '.implode(',',$sets).' WHERE id=?')->execute($params);
    sponsored_project_event($pdo,(int)$comp['campaign_id'],(int)$comp['assignment_id'],$comp['accepted_submission_id']?(int)$comp['accepted_submission_id']:null,(int)$admin['id'],'project_compensation_'.$status,['compensation_public_id'=>$comp['public_id'],'from_status'=>$from,'to_status'=>$status,'payment_reference'=>$paymentReference!==''?$paymentReference:null]);
    if(function_exists('notification_create')){
      $type='research_sponsored_compensation_'.$status;$body=match($status){'approved_for_payment'=>'Your Sponsored Project compensation was approved for payment.','paid'=>'Your Sponsored Project compensation was marked paid.','voided'=>'Your Sponsored Project compensation was voided.',default=>'Your Sponsored Project compensation changed.'};
      $submissionPublic=(string)($comp['accepted_submission_public_id']??'');
      notification_create($pdo,(int)$comp['researcher_user_id'],(int)$admin['id'],$type,$submissionPublic!==''?'sponsored_project_submission':null,$submissionPublic!==''?$submissionPublic:null,$body,['category'=>'research','dedupe_key'=>'sponsored-comp:'.$comp['public_id'].':'.$status]);
    }
    return sponsored_project_compensation_get($pdo,$compPublicId)??[];
}
function sponsored_project_compensations_for_researcher(PDO $pdo,int $userId,int $limit=100): array {
    if(!sponsored_project_compensation_ready($pdo))return [];$limit=max(1,min(250,$limit));
    $q=$pdo->prepare('SELECT assignment_id FROM sponsored_research_project_compensations WHERE researcher_user_id=? ORDER BY id DESC LIMIT '.$limit);$q->execute([$userId]);
    return array_values(array_filter(array_map(fn($id)=>sponsored_project_compensation_for_assignment($pdo,(int)$id),$q->fetchAll(PDO::FETCH_COLUMN)?:[])));
}
function sponsored_project_compensation_summary(PDO $pdo,int $campaignId): array {
    $q=$pdo->prepare("SELECT status,COUNT(*) records,COALESCE(SUM(amount_cents),0) amount_cents FROM sponsored_research_project_compensations WHERE campaign_id=? GROUP BY status");$q->execute([$campaignId]);
    $out=['pending'=>0,'earned'=>0,'approved_for_payment'=>0,'paid'=>0,'voided'=>0,'total_cents'=>0];foreach($q->fetchAll()?:[] as $r){$out[(string)$r['status']]=(int)$r['amount_cents'];if($r['status']!=='voided')$out['total_cents']+=(int)$r['amount_cents'];}return $out;
}
function sponsored_project_public_list(PDO $pdo,string $query='',int $limit=100): array {
    $limit=max(1,min(250,$limit));$query=mb_substr(trim($query),0,120);$params=[];$where="c.status='open' AND c.access_mode='public' AND c.researcher_compensation_cents>0";
    if($query!==''){$where.=" AND (c.title LIKE ? OR c.brief LIKE ? OR c.objective LIKE ? OR sp.organization_name LIKE ?)";$like='%'.$query.'%';$params=[$like,$like,$like,$like];}
    $q=$pdo->prepare("SELECT c.public_id,c.title,c.brief,c.objective,c.budget_currency,c.researcher_compensation_cents,c.compensation_model,c.max_participants,c.starts_at,c.submission_deadline,c.review_deadline,c.eligibility_json,c.created_at,sp.organization_name,
      (SELECT COUNT(*) FROM sponsored_research_agent_assignments a WHERE a.campaign_id=c.id AND a.status IN ('active','paused','completed')) assigned_count
      FROM sponsored_research_campaigns c JOIN sponsor_account_profiles sp ON sp.id=c.sponsor_profile_id
      WHERE $where ORDER BY c.created_at DESC LIMIT $limit");$q->execute($params);$rows=$q->fetchAll()?:[];
    foreach($rows as &$row){$row['eligibility']=sponsored_research_campaign_json($row['eligibility_json']??null);unset($row['eligibility_json']);$max=(int)($row['max_participants']??0);$row['spots_remaining']=$max>0?max(0,$max-(int)$row['assigned_count']):null;}unset($row);return $rows;
}
function sponsored_project_public_get(PDO $pdo,string $publicId): ?array {
    $q=$pdo->prepare("SELECT c.public_id,c.id,c.title,c.brief,c.objective,c.budget_currency,c.researcher_compensation_cents,c.compensation_model,c.max_participants,c.starts_at,c.submission_deadline,c.review_deadline,c.eligibility_json,c.created_at,sp.organization_name
      FROM sponsored_research_campaigns c JOIN sponsor_account_profiles sp ON sp.id=c.sponsor_profile_id WHERE c.public_id=? AND c.status='open' AND c.access_mode='public' AND c.researcher_compensation_cents>0 LIMIT 1");
    $q->execute([trim($publicId)]);$row=$q->fetch();if(!$row)return null;$row['eligibility']=sponsored_research_campaign_json($row['eligibility_json']??null);unset($row['eligibility_json']);
    $row['questions']=sponsored_research_campaign_questions($pdo,(int)$row['id']);unset($row['id']);return $row;
}
