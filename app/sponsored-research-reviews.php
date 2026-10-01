<?php
declare(strict_types=1);

function sponsored_research_reviews_ready(PDO $pdo): bool {
    try{return sponsored_research_submissions_ready($pdo)
      && installer_table_exists($pdo,'sponsored_research_review_cases')
      && installer_table_exists($pdo,'sponsored_research_review_assignments')
      && installer_table_exists($pdo,'sponsored_research_review_responses')
      && installer_table_exists($pdo,'sponsored_research_disputes')
      && installer_table_exists($pdo,'sponsored_research_review_events');}
    catch(Throwable $e){return false;}
}
function sponsored_research_review_event(PDO $pdo,int $caseId,?int $actorUserId,string $type,array $payload=[]): void {
    $pdo->prepare('INSERT INTO sponsored_research_review_events(public_id,review_case_id,actor_user_id,event_type,payload_json) VALUES(?,?,?,?,?)')
      ->execute([ulid_like(),$caseId,$actorUserId,mb_substr($type,0,80),$payload?data_attribution_encode($payload):null]);
}
function sponsored_research_review_criteria_normalize(mixed $input): array {
    if(is_string($input)){
        $lines=preg_split('/\r?\n+/',$input)?:[];$input=[];foreach($lines as $line){$line=trim($line);if($line!=='')$input[]=['key'=>strtolower((string)preg_replace('/[^a-z0-9]+/i','_',mb_substr($line,0,40))),'label'=>$line];}
    }
    $out=[];foreach(array_slice((array)$input,0,20) as $i=>$raw){if(is_string($raw))$raw=['label'=>$raw];if(!is_array($raw))continue;$label=mb_substr(trim((string)($raw['label']??'')),0,255);if($label==='')continue;$key=mb_substr(strtolower((string)preg_replace('/[^a-z0-9_]+/','_',trim((string)($raw['key']??'criterion_'.($i+1))))),0,64);$out[]=['key'=>$key?:'criterion_'.($i+1),'label'=>$label,'weight'=>max(1,min(100,(int)($raw['weight']??1)))];}
    if(!$out)$out=[['key'=>'evidence_strength','label'=>'Evidence strength','weight'=>1],['key'=>'completeness','label'=>'Completeness','weight'=>1],['key'=>'methodology','label'=>'Methodology','weight'=>1],['key'=>'originality','label'=>'Originality','weight'=>1],['key'=>'brief_alignment','label'=>'Adherence to brief','weight'=>1]];
    return $out;
}
function sponsored_research_review_case_get(PDO $pdo,string $publicId): ?array {
    $q=$pdo->prepare("SELECT rc.*,sv.public_id submission_version_public_id,sv.revision_number,sv.snapshot_hash,s.public_id submission_public_id,s.researcher_user_id,s.title submission_title,s.summary submission_summary,c.public_id campaign_public_id,c.title campaign_title
      FROM sponsored_research_review_cases rc
      JOIN sponsored_research_submission_versions sv ON sv.id=rc.submission_version_id
      JOIN sponsored_research_submissions s ON s.id=rc.submission_id
      JOIN sponsored_research_campaigns c ON c.id=rc.campaign_id
      WHERE rc.public_id=? LIMIT 1");
    $q->execute([trim($publicId)]);$r=$q->fetch();if(!$r)return null;$r['criteria']=json_decode((string)$r['criteria_json'],true)?:[];return $r;
}
function sponsored_research_review_case_for_version(PDO $pdo,int $versionId): ?array {
    $q=$pdo->prepare('SELECT public_id FROM sponsored_research_review_cases WHERE submission_version_id=? LIMIT 1');$q->execute([$versionId]);$p=$q->fetchColumn();return $p?sponsored_research_review_case_get($pdo,(string)$p):null;
}
function sponsored_research_review_latest_version(PDO $pdo,array $submission): array {
    $q=$pdo->prepare('SELECT * FROM sponsored_research_submission_versions WHERE submission_id=? ORDER BY revision_number DESC LIMIT 1');$q->execute([(int)$submission['id']]);$v=$q->fetch();if(!$v)throw new RuntimeException('Submission has no submitted revision.');return $v;
}
function sponsored_research_review_open(PDO $pdo,array $viewer,string $submissionPublicId,array $input=[]): array {
    $submission=sponsored_research_submission_get($pdo,$submissionPublicId);if(!$submission)throw new RuntimeException('Submission not found.');
    sponsored_research_campaign_require_manage($pdo,$viewer,(string)$submission['campaign_public_id']);
    if((string)$submission['status']!=='submitted')throw new RuntimeException('Only submitted research can enter review.');
    $version=sponsored_research_review_latest_version($pdo,$submission);$existing=sponsored_research_review_case_for_version($pdo,(int)$version['id']);if($existing)return $existing;
    $criteria=sponsored_research_review_criteria_normalize($input['criteria']??[]);$criteriaJson=data_attribution_encode($criteria);$blind=!array_key_exists('blind_review',$input)||!empty($input['blind_review']);
    $public=ulid_like();$pdo->prepare("INSERT INTO sponsored_research_review_cases(public_id,campaign_id,submission_id,submission_version_id,opened_by_user_id,status,blind_review,criteria_json,criteria_hash) VALUES(?,?,?,?,?,'open',?,?,?)")
      ->execute([$public,(int)$submission['campaign_id'],(int)$submission['id'],(int)$version['id'],(int)$viewer['id'],$blind?1:0,$criteriaJson,hash('sha256',$criteriaJson)]);
    $id=(int)$pdo->lastInsertId();sponsored_research_review_event($pdo,$id,(int)$viewer['id'],'review_opened',['submission_version_public_id'=>$version['public_id'],'revision'=>(int)$version['revision_number'],'snapshot_hash'=>$version['snapshot_hash'],'blind_review'=>$blind]);
    return sponsored_research_review_case_get($pdo,$public)??[];
}
function sponsored_research_review_assign(PDO $pdo,array $viewer,string $casePublicId,string $username,string $role='independent'): array {
    $case=sponsored_research_review_case_get($pdo,$casePublicId);if(!$case)throw new RuntimeException('Review case not found.');sponsored_research_campaign_require_manage($pdo,$viewer,(string)$case['campaign_public_id']);
    $role=in_array($role,['sponsor','independent','subject_matter'],true)?$role:'independent';$q=$pdo->prepare("SELECT * FROM users WHERE username=? AND status='active' LIMIT 1");$q->execute([trim($username)]);$reviewer=$q->fetch();if(!$reviewer)throw new RuntimeException('Reviewer not found.');if((int)$reviewer['id']===(int)$case['researcher_user_id'])throw new RuntimeException('The submitting researcher cannot review their own work.');
    $pdo->prepare("INSERT INTO sponsored_research_review_assignments(public_id,review_case_id,reviewer_user_id,assigned_by_user_id,reviewer_role,status) VALUES(?,?,?,?,?,'assigned') ON DUPLICATE KEY UPDATE assigned_by_user_id=VALUES(assigned_by_user_id),reviewer_role=VALUES(reviewer_role),status='assigned',completed_at=NULL")
      ->execute([ulid_like(),(int)$case['id'],(int)$reviewer['id'],(int)$viewer['id'],$role]);
    $pdo->prepare("UPDATE sponsored_research_review_cases SET status=IF(status='open','in_review',status),updated_at=NOW() WHERE id=?")->execute([(int)$case['id']]);
    sponsored_research_review_event($pdo,(int)$case['id'],(int)$viewer['id'],'reviewer_assigned',['reviewer_user_id'=>(int)$reviewer['id'],'role'=>$role]);
    $q=$pdo->prepare('SELECT * FROM sponsored_research_review_assignments WHERE review_case_id=? AND reviewer_user_id=?');$q->execute([(int)$case['id'],(int)$reviewer['id']]);return $q->fetch()?:[];
}
function sponsored_research_review_assignment_for(PDO $pdo,array $viewer,string $casePublicId): ?array {
    $case=sponsored_research_review_case_get($pdo,$casePublicId);if(!$case)return null;$q=$pdo->prepare("SELECT a.*,rc.blind_review,rc.status review_status,sv.snapshot_json,sv.snapshot_hash,s.title submission_title,s.summary submission_summary,s.researcher_user_id,u.display_name researcher_display_name,u.username researcher_username
      FROM sponsored_research_review_assignments a
      JOIN sponsored_research_review_cases rc ON rc.id=a.review_case_id
      JOIN sponsored_research_submission_versions sv ON sv.id=rc.submission_version_id
      JOIN sponsored_research_submissions s ON s.id=rc.submission_id
      JOIN users u ON u.id=s.researcher_user_id
      WHERE a.review_case_id=? AND a.reviewer_user_id=? LIMIT 1");
    $q->execute([(int)$case['id'],(int)$viewer['id']]);$r=$q->fetch();if(!$r)return null;
    if((int)$r['blind_review']===1){$r['researcher_display_name']='Anonymous Researcher';$r['researcher_username']=null;$r['researcher_user_id']=null;}
    return $r;
}
function sponsored_research_review_quality_normalize(array $criteria,mixed $input): array {
    if(!is_array($input))$input=[];$out=[];foreach($criteria as $c){$key=(string)$c['key'];$score=(int)($input[$key]??0);if($score<1||$score>5)throw new InvalidArgumentException('Every quality criterion must be scored from 1 to 5.');$out[$key]=['score'=>$score,'label'=>$c['label'],'weight'=>(int)$c['weight']];}return $out;
}
function sponsored_research_review_respond(PDO $pdo,array $viewer,string $casePublicId,string $recommendation,array $quality,string $comment=''): array {
    $case=sponsored_research_review_case_get($pdo,$casePublicId);if(!$case)throw new RuntimeException('Review case not found.');$assignment=sponsored_research_review_assignment_for($pdo,$viewer,$casePublicId);if(!$assignment||$assignment['status']!=='assigned')throw new RuntimeException('You are not assigned to this review.');
    if(!in_array($recommendation,['accept','request_revision','reject','abstain'],true))throw new InvalidArgumentException('Invalid review recommendation.');
    $qualityNorm=$recommendation==='abstain'?[]:sponsored_research_review_quality_normalize((array)$case['criteria'],$quality);$qualityJson=data_attribution_encode($qualityNorm);$public=ulid_like();
    $pdo->prepare('INSERT INTO sponsored_research_review_responses(public_id,review_case_id,assignment_id,reviewer_user_id,recommendation,quality_json,quality_hash,comment) VALUES(?,?,?,?,?,?,?,?)')
      ->execute([$public,(int)$case['id'],(int)$assignment['id'],(int)$viewer['id'],$recommendation,$qualityJson,hash('sha256',$qualityJson),trim($comment)!==''?trim($comment):null]);
    $pdo->prepare("UPDATE sponsored_research_review_assignments SET status='completed',completed_at=NOW() WHERE id=?")->execute([(int)$assignment['id']]);
    sponsored_research_review_event($pdo,(int)$case['id'],(int)$viewer['id'],'review_response_submitted',['response_public_id'=>$public,'recommendation'=>$recommendation,'quality_hash'=>hash('sha256',$qualityJson)]);
    $q=$pdo->prepare('SELECT * FROM sponsored_research_review_responses WHERE public_id=?');$q->execute([$public]);return $q->fetch()?:[];
}
function sponsored_research_review_responses(PDO $pdo,int $caseId): array {
    $q=$pdo->prepare("SELECT r.*,a.reviewer_role,u.display_name,u.username FROM sponsored_research_review_responses r JOIN sponsored_research_review_assignments a ON a.id=r.assignment_id JOIN users u ON u.id=r.reviewer_user_id WHERE r.review_case_id=? ORDER BY r.id");$q->execute([$caseId]);return $q->fetchAll()?:[];
}
function sponsored_research_review_decide(PDO $pdo,array $viewer,string $casePublicId,string $decision,string $note=''): array {
    $case=sponsored_research_review_case_get($pdo,$casePublicId);if(!$case)throw new RuntimeException('Review case not found.');sponsored_research_campaign_require_manage($pdo,$viewer,(string)$case['campaign_public_id']);
    if(!in_array($decision,['accepted','revision_requested','rejected'],true))throw new InvalidArgumentException('Invalid final review decision.');
    if(in_array((string)$case['status'],['accepted','rejected','resolved'],true))throw new RuntimeException('This review case already has a terminal decision.');
    $responses=sponsored_research_review_responses($pdo,(int)$case['id']);$note=trim($note);if($decision!=='accepted'&&$note==='')throw new InvalidArgumentException('A decision note is required for revision requests and rejections.');
    $pdo->beginTransaction();try{
      $eligible=$decision==='accepted'?1:0;
      $pdo->prepare('UPDATE sponsored_research_review_cases SET status=?,final_decision=?,decision_note=?,decided_by_user_id=?,decided_at=NOW(),compensation_eligible=?,updated_at=NOW() WHERE id=?')
        ->execute([$decision,$decision,$note!==''?$note:null,(int)$viewer['id'],$eligible,(int)$case['id']]);
      $pdo->prepare('UPDATE sponsored_research_submissions SET status=?,updated_at=NOW() WHERE id=?')->execute([$decision,(int)$case['submission_id']]);
      sponsored_research_review_event($pdo,(int)$case['id'],(int)$viewer['id'],'final_decision',['decision'=>$decision,'review_response_count'=>count($responses),'compensation_eligible'=>(bool)$eligible,'submission_version_public_id'=>$case['submission_version_public_id'],'snapshot_hash'=>$case['snapshot_hash']]);
      if($decision==='accepted')data_provenance_edge_record($pdo,'sponsored_submission_version',(string)$case['submission_version_public_id'],(string)$case['revision_number'],'accepted_in','sponsored_campaign',(string)$case['campaign_public_id'],null,(int)$viewer['id'],null,null,date('Y-m-d H:i:s'),['review_case_public_id'=>$case['public_id']]);
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return sponsored_research_review_case_get($pdo,$casePublicId)??[];
}
function sponsored_research_dispute_open(PDO $pdo,array $viewer,string $casePublicId,string $reason,array $evidence=[]): array {
    $case=sponsored_research_review_case_get($pdo,$casePublicId);if(!$case)throw new RuntimeException('Review case not found.');
    $isResearcher=(int)$case['researcher_user_id']===(int)$viewer['id'];$isSponsor=false;try{sponsored_research_campaign_require_manage($pdo,$viewer,(string)$case['campaign_public_id']);$isSponsor=true;}catch(Throwable $e){}
    if(!$isResearcher&&!$isSponsor)throw new RuntimeException('You cannot dispute this review.');
    if(!in_array((string)$case['status'],['accepted','rejected','revision_requested'],true))throw new RuntimeException('Only a completed review decision can be disputed.');
    $reason=trim($reason);if($reason==='')throw new InvalidArgumentException('Dispute reason is required.');$public=ulid_like();$evidenceJson=$evidence?data_attribution_encode($evidence):null;
    $pdo->beginTransaction();try{
      $pdo->prepare("INSERT INTO sponsored_research_disputes(public_id,review_case_id,submission_id,opened_by_user_id,status,reason,evidence_json) VALUES(?,?,?,?,'open',?,?)")->execute([$public,(int)$case['id'],(int)$case['submission_id'],(int)$viewer['id'],$reason,$evidenceJson]);
      $pdo->prepare("UPDATE sponsored_research_review_cases SET status='disputed',updated_at=NOW() WHERE id=?")->execute([(int)$case['id']]);
      sponsored_research_review_event($pdo,(int)$case['id'],(int)$viewer['id'],'dispute_opened',['dispute_public_id'=>$public,'reason_hash'=>hash('sha256',$reason)]);
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $q=$pdo->prepare('SELECT * FROM sponsored_research_disputes WHERE public_id=?');$q->execute([$public]);return $q->fetch()?:[];
}
function sponsored_research_dispute_resolve(PDO $pdo,array $viewer,string $disputePublicId,string $resolution,string $note=''): array {
    if(($viewer['role']??'')!=='admin')throw new RuntimeException('Administrator access is required to resolve Sponsored Research disputes.');
    $q=$pdo->prepare("SELECT d.*,rc.final_decision,rc.id review_case_id FROM sponsored_research_disputes d JOIN sponsored_research_review_cases rc ON rc.id=d.review_case_id WHERE d.public_id=? LIMIT 1");$q->execute([trim($disputePublicId)]);$d=$q->fetch();if(!$d)throw new RuntimeException('Dispute not found.');if($d['status']==='resolved')return $d;
    if(!in_array($resolution,['upheld','modified','dismissed'],true))throw new InvalidArgumentException('Invalid dispute resolution.');
    $note=trim($note);if($note==='')throw new InvalidArgumentException('Resolution note is required.');
    $pdo->beginTransaction();try{
      $pdo->prepare("UPDATE sponsored_research_disputes SET status='resolved',resolution=?,resolution_note=?,resolved_by_user_id=?,resolved_at=NOW() WHERE id=?")->execute([$resolution,$note,(int)$viewer['id'],(int)$d['id']]);
      $pdo->prepare("UPDATE sponsored_research_review_cases SET status='resolved',updated_at=NOW() WHERE id=?")->execute([(int)$d['review_case_id']]);
      sponsored_research_review_event($pdo,(int)$d['review_case_id'],(int)$viewer['id'],'dispute_resolved',['dispute_public_id'=>$d['public_id'],'resolution'=>$resolution,'note_hash'=>hash('sha256',$note)]);
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $q=$pdo->prepare('SELECT * FROM sponsored_research_disputes WHERE id=?');$q->execute([(int)$d['id']]);return $q->fetch()?:[];
}
function sponsored_research_review_cases_for_campaign(PDO $pdo,array $viewer,string $campaignPublicId,int $limit=200): array {
    $campaign=sponsored_research_campaign_require_manage($pdo,$viewer,$campaignPublicId);$q=$pdo->prepare("SELECT rc.*,s.public_id submission_public_id,s.title submission_title,u.display_name researcher_display_name,u.username researcher_username,sv.revision_number FROM sponsored_research_review_cases rc JOIN sponsored_research_submissions s ON s.id=rc.submission_id JOIN sponsored_research_submission_versions sv ON sv.id=rc.submission_version_id JOIN users u ON u.id=s.researcher_user_id WHERE rc.campaign_id=? ORDER BY rc.updated_at DESC LIMIT ".max(1,min(500,$limit)));$q->execute([(int)$campaign['id']]);return $q->fetchAll()?:[];
}
