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
function sponsored_project_compensation_mark_earned(PDO $pdo,array $submission,array $actor): array {
    $campaign=sponsored_research_campaign_by_public($pdo,(string)$submission['campaign_public_id']);if(!$campaign)throw new RuntimeException('Sponsored Project not found.');
    $q=$pdo->prepare('SELECT * FROM sponsored_research_agent_assignments WHERE id=? LIMIT 1');$q->execute([(int)$submission['assignment_id']]);$assignment=$q->fetch();if(!$assignment)throw new RuntimeException('Sponsored Project assignment not found.');
    $comp=sponsored_project_compensation_for_assignment($pdo,(int)$assignment['id']);
    if(!$comp)throw new RuntimeException('Researcher compensation was not agreed at assignment. Acceptance requires an explicit agreement.');
    if((int)$comp['research_agent_id']!==(int)$submission['research_agent_id'])throw new RuntimeException('The submitted Agent differs from the frozen compensation agreement.');
    if(in_array((string)$comp['status'],['earned','approved_for_payment','paid'],true)){
        if((int)$comp['accepted_submission_id']!==(int)$submission['id'])throw new RuntimeException('Compensation is already linked to another accepted submission.');
        return $comp;
    }
    if((string)$comp['status']==='voided')throw new RuntimeException('Voided project compensation cannot be earned.');
    $pdo->prepare("UPDATE sponsored_research_project_compensations SET status='earned',accepted_submission_id=?,earned_at=NOW(),updated_by_user_id=? WHERE id=?")
      ->execute([(int)$submission['id'],(int)$actor['id'],(int)$comp['id']]);
    sponsored_project_event($pdo,(int)$submission['campaign_id'],(int)$submission['assignment_id'],(int)$submission['id'],(int)$actor['id'],'project_compensation_earned',['compensation_public_id'=>$comp['public_id'],'amount_cents'=>(int)$comp['amount_cents'],'currency'=>$comp['currency']]);
    if(function_exists('notification_create'))notification_create($pdo,(int)$submission['researcher_user_id'],(int)$actor['id'],'research_sponsored_compensation_earned','sponsored_project_submission',(string)$submission['public_id'],'Your Sponsored Project compensation is now earned.',['category'=>'research','dedupe_key'=>'sponsored-comp-earned:'.$comp['public_id']]);
    return sponsored_project_compensation_for_assignment($pdo,(int)$submission['assignment_id'])??[];
}
function sponsored_project_compensation_admin_transition(PDO $pdo,array $admin,string $compPublicId,string $status,string $paymentReference='',string $note=''): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required.');
    $pdo->beginTransaction();
    try{
    $q=$pdo->prepare('SELECT assignment_id FROM sponsored_research_project_compensations WHERE public_id=? FOR UPDATE');
    $q->execute([$compPublicId]);$assignmentId=(int)($q->fetchColumn()?:0);
    if(!$assignmentId)throw new RuntimeException('Sponsored Project compensation record not found.');
    $comp=sponsored_project_compensation_for_assignment($pdo,$assignmentId);
    if(!$comp)throw new RuntimeException('Sponsored Project compensation record not found.');
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
    $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
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
    $limit=max(1,min(250,$limit));$query=mb_substr(trim($query),0,120);$params=[];$where="c.status='open' AND c.access_mode='public' AND c.researcher_compensation_cents>0 AND (c.starts_at IS NULL OR c.starts_at<=NOW()) AND (c.submission_deadline IS NULL OR c.submission_deadline>NOW()) AND (c.max_participants IS NULL OR (SELECT COUNT(*) FROM sponsored_research_agent_assignments ax WHERE ax.campaign_id=c.id AND ax.status IN ('active','paused','completed'))<c.max_participants)";
    if($query!==''){$where.=" AND (c.title LIKE ? OR c.brief LIKE ? OR c.objective LIKE ? OR sp.organization_name LIKE ?)";$like='%'.$query.'%';$params=[$like,$like,$like,$like];}
    $q=$pdo->prepare("SELECT c.public_id,c.title,c.brief,c.objective,c.budget_currency,c.researcher_compensation_cents,c.compensation_model,c.max_participants,c.starts_at,c.submission_deadline,c.review_deadline,c.eligibility_json,c.disclosure_json,c.created_at,sp.organization_name,
      (SELECT COUNT(*) FROM sponsored_research_agent_assignments a WHERE a.campaign_id=c.id AND a.status IN ('active','paused','completed')) assigned_count
      FROM sponsored_research_campaigns c JOIN sponsor_account_profiles sp ON sp.id=c.sponsor_profile_id
      WHERE $where ORDER BY c.created_at DESC LIMIT $limit");$q->execute($params);$rows=$q->fetchAll()?:[];
    foreach($rows as &$row){$row['eligibility']=sponsored_research_campaign_json($row['eligibility_json']??null);unset($row['eligibility_json']);$max=(int)($row['max_participants']??0);$row['spots_remaining']=$max>0?max(0,$max-(int)$row['assigned_count']):null;}unset($row);return $rows;
}
function sponsored_project_public_get(PDO $pdo,string $publicId): ?array {
    $q=$pdo->prepare("SELECT c.public_id,c.id,c.title,c.brief,c.objective,c.budget_currency,c.researcher_compensation_cents,c.compensation_model,c.max_participants,c.starts_at,c.submission_deadline,c.review_deadline,c.eligibility_json,c.created_at,sp.organization_name
      FROM sponsored_research_campaigns c JOIN sponsor_account_profiles sp ON sp.id=c.sponsor_profile_id WHERE c.public_id=? AND c.status='open' AND c.access_mode='public' AND c.researcher_compensation_cents>0 AND (c.starts_at IS NULL OR c.starts_at<=NOW()) AND (c.submission_deadline IS NULL OR c.submission_deadline>NOW()) AND (c.max_participants IS NULL OR (SELECT COUNT(*) FROM sponsored_research_agent_assignments ax WHERE ax.campaign_id=c.id AND ax.status IN ('active','paused','completed'))<c.max_participants) LIMIT 1");
    $q->execute([trim($publicId)]);$row=$q->fetch();if(!$row)return null;$row['eligibility']=sponsored_research_campaign_json($row['eligibility_json']??null);unset($row['eligibility_json']);$row['disclosures']=sponsored_research_campaign_json($row['disclosure_json']??null);unset($row['disclosure_json']);
    $row['questions']=sponsored_research_campaign_questions($pdo,(int)$row['id']);unset($row['id']);return $row;
}

function sponsored_project_sample_settings(PDO $pdo): array {
    if(!installer_table_exists($pdo,'sponsored_research_project_settings'))return ['sample_data_enabled'=>0];
    $q=$pdo->query('SELECT * FROM sponsored_research_project_settings WHERE id=1');return $q->fetch()?:['id'=>1,'sample_data_enabled'=>0];
}
function sponsored_project_sample_toggle(PDO $pdo,array $admin,bool $enabled): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required.');
    if(!installer_table_exists($pdo,'sponsored_research_project_settings'))throw new RuntimeException('Sponsored Project settings require the latest database upgrade.');
    $pdo->prepare('INSERT INTO sponsored_research_project_settings(id,sample_data_enabled,updated_by_user_id) VALUES(1,?,?) ON DUPLICATE KEY UPDATE sample_data_enabled=VALUES(sample_data_enabled),updated_by_user_id=VALUES(updated_by_user_id),updated_at=NOW()')->execute([$enabled?1:0,(int)$admin['id']]);
    return sponsored_project_sample_settings($pdo);
}
function sponsored_project_sample_projects(PDO $pdo): array {
    $settings=sponsored_project_sample_settings($pdo);if(empty($settings['sample_data_enabled']))return [];
    return [
      ['public_id'=>'sample-market-ai-001','title'=>'AI Adoption in Independent Retail','status'=>'open','access_mode'=>'public','organization_name'=>'Northstar Market Research','budget_currency'=>'USD','researcher_compensation_cents'=>45000,'budget_cents'=>500000,'assigned_agents'=>3,'submissions'=>2,'compensation_earned_cents'=>45000,'compensation_approved_cents'=>0,'compensation_paid_cents'=>0,'sample_data'=>1,'brief'=>'Research how independent retailers are adopting AI for inventory, customer service, merchandising, and operations.','submission_deadline'=>'2026-11-15 23:59:00','requirements'=>['Identity verified','Retail or SMB research experience','English']],
      ['public_id'=>'sample-energy-002','title'=>'Residential Battery Buying Drivers','status'=>'open','access_mode'=>'public','organization_name'=>'Gridline Insights','budget_currency'=>'USD','researcher_compensation_cents'=>65000,'budget_cents'=>750000,'assigned_agents'=>5,'submissions'=>4,'compensation_earned_cents'=>130000,'compensation_approved_cents'=>65000,'compensation_paid_cents'=>65000,'sample_data'=>1,'brief'=>'Study purchase motivations, objections, financing expectations, and installer trust factors for residential battery systems.','submission_deadline'=>'2026-11-30 23:59:00','requirements'=>['Identity verified','Energy or consumer research','English']],
      ['public_id'=>'sample-food-003','title'=>'Premium Frozen Pizza Customer Research','status'=>'draft','access_mode'=>'private','organization_name'=>'Mesa Consumer Lab','budget_currency'=>'USD','researcher_compensation_cents'=>30000,'budget_cents'=>300000,'assigned_agents'=>0,'submissions'=>0,'compensation_earned_cents'=>0,'compensation_approved_cents'=>0,'compensation_paid_cents'=>0,'sample_data'=>1,'brief'=>'Explore customer expectations for premium frozen pizza including crust, toppings, price sensitivity, and convenience.','submission_deadline'=>'2026-12-10 23:59:00','requirements'=>['Approved Research Account','Consumer research experience']]
    ];
}

/**
 * Public previews are never operational campaign records. Reuse the Admin's
 * canonical samples without exposing draft/private examples or appending them
 * to real listing, assignment, submission, or compensation queries.
 */
function sponsored_project_public_sample_rows(array $samples,string $query=''): array {
    $needle=mb_strtolower(mb_substr(trim($query),0,120));
    return array_values(array_filter($samples,static function(array $sample)use($needle):bool{
        if(empty($sample['sample_data'])||(string)($sample['status']??'')!=='open'||(string)($sample['access_mode']??'')!=='public')return false;
        if($needle==='')return true;
        $haystack=mb_strtolower(implode(' ',[(string)($sample['title']??''),(string)($sample['brief']??''),(string)($sample['organization_name']??''),implode(' ',(array)($sample['requirements']??[]))]));
        return mb_strpos($haystack,$needle)!==false;
    }));
}
function sponsored_project_public_samples(PDO $pdo,string $query=''): array {
    return sponsored_project_public_sample_rows(sponsored_project_sample_projects($pdo),$query);
}
