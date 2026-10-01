<?php
declare(strict_types=1);

function sponsored_projects_ready(PDO $pdo): bool {
    try{return sponsored_research_participation_ready($pdo)
      && installer_table_exists($pdo,'sponsored_research_agent_assignments')
      && installer_table_exists($pdo,'sponsored_research_project_submissions')
      && installer_table_exists($pdo,'sponsored_research_project_submission_assets')
      && installer_table_exists($pdo,'sponsored_research_agent_events');}
    catch(Throwable $e){return false;}
}
function sponsored_project_event(PDO $pdo,int $campaignId,?int $assignmentId,?int $submissionId,?int $actorId,string $type,array $payload=[]): void {
    $pdo->prepare('INSERT INTO sponsored_research_agent_events(public_id,campaign_id,assignment_id,project_submission_id,actor_user_id,event_type,payload_json) VALUES(?,?,?,?,?,?,?)')
      ->execute([ulid_like(),$campaignId,$assignmentId,$submissionId,$actorId,mb_substr($type,0,80),$payload?data_attribution_encode($payload):null]);
}
function sponsored_project_notify_admins(PDO $pdo,?int $actorUserId,string $type,string $submissionPublicId,string $body,array $context=[]): void {
    if(!function_exists('notification_create'))return;
    try{$ids=$pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'")->fetchAll(PDO::FETCH_COLUMN)?:[];}catch(Throwable $e){return;}
    foreach($ids as $id)notification_create($pdo,(int)$id,$actorUserId,$type,'sponsored_project_submission',$submissionPublicId,$body,['category'=>'research','dedupe_key'=>$type.':'.$submissionPublicId,'context'=>$context]);
}
function sponsored_project_assignment(PDO $pdo,int $campaignId,int $userId): ?array {
    $q=$pdo->prepare("SELECT a.*,ra.public_id research_agent_public_id,ra.name research_agent_name,ra.profile_image_url,ra.project_id,rp.public_id project_public_id,rp.title project_title
      FROM sponsored_research_agent_assignments a JOIN research_agents ra ON ra.id=a.research_agent_id JOIN research_projects rp ON rp.id=ra.project_id
      WHERE a.campaign_id=? AND a.researcher_user_id=? LIMIT 1");$q->execute([$campaignId,$userId]);return $q->fetch()?:null;
}
function sponsored_project_owned_agents(PDO $pdo,array $viewer): array {
    $q=$pdo->prepare("SELECT ra.public_id,ra.name,ra.profile_image_url,rp.public_id project_public_id,rp.title project_title FROM research_agents ra JOIN research_projects rp ON rp.id=ra.project_id WHERE ra.owner_user_id=? AND ra.status='active' ORDER BY ra.updated_at DESC,ra.id DESC");
    $q->execute([(int)$viewer['id']]);return $q->fetchAll()?:[];
}
function sponsored_project_listings_for_researcher(PDO $pdo,array $viewer,int $limit=100): array {
    research_account_require_approved($pdo,$viewer);$q=$pdo->prepare("SELECT c.public_id FROM sponsored_research_campaigns c LEFT JOIN sponsored_research_campaign_invites i ON i.campaign_id=c.id AND i.researcher_user_id=? AND i.status='pending' WHERE c.status='open' AND (c.access_mode='public' OR i.id IS NOT NULL) ORDER BY c.created_at DESC LIMIT ".max(1,min(250,$limit)));
    $q->execute([(int)$viewer['id']]);$out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $public){$c=sponsored_research_campaign_by_public($pdo,(string)$public);if(!$c)continue;$c['eligibility_check']=sponsored_research_campaign_eligibility_check($pdo,$viewer,$c);$c['assignment']=sponsored_project_assignment($pdo,(int)$c['id'],(int)$viewer['id']);$out[]=$c;}return $out;
}
function sponsored_project_assign_agent(PDO $pdo,array $viewer,string $campaignPublicId,string $agentPublicId,array $input): array {
    research_account_require_approved($pdo,$viewer);$campaign=sponsored_research_campaign_by_public($pdo,$campaignPublicId);if(!$campaign||!sponsored_research_campaign_visible_to_researcher($pdo,$viewer,$campaign))throw new RuntimeException('Sponsored Project is unavailable.');
    $elig=sponsored_research_campaign_eligibility_check($pdo,$viewer,$campaign);if(empty($elig['eligible']))throw new RuntimeException('Your Research Account does not meet this project’s eligibility requirements.');
    if((int)($campaign['researcher_compensation_cents']??0)<=0)throw new RuntimeException('This Sponsored Project is not accepting researchers until compensation is configured.');
    $participation=sponsored_research_participation_get($pdo,(int)$campaign['id'],(int)$viewer['id']);
    if(!$participation||$participation['status']!=='active'||(int)$participation['campaign_revision_accepted']!==(int)$campaign['current_revision'])$participation=sponsored_research_campaign_join($pdo,$viewer,$campaignPublicId,$input);
    $agent=research_agent_access($pdo,$viewer,$agentPublicId);if(!$agent||(int)$agent['owner_user_id']!==(int)$viewer['id']||$agent['status']!=='active')throw new RuntimeException('Choose one of your active Research Agents.');
    $existing=sponsored_project_assignment($pdo,(int)$campaign['id'],(int)$viewer['id']);$public=$existing?(string)$existing['public_id']:ulid_like();
    if($existing){$pdo->prepare("UPDATE sponsored_research_agent_assignments SET participation_id=?,research_agent_id=?,status='active',agent_submit_enabled=1,assigned_at=NOW(),withdrawn_at=NULL,removed_at=NULL WHERE id=?")->execute([(int)$participation['id'],(int)$agent['id'],(int)$existing['id']]);$id=(int)$existing['id'];}
    else{$pdo->prepare("INSERT INTO sponsored_research_agent_assignments(public_id,campaign_id,participation_id,researcher_user_id,research_agent_id,status) VALUES(?,?,?,?,?,'active')")->execute([$public,(int)$campaign['id'],(int)$participation['id'],(int)$viewer['id'],(int)$agent['id']]);$id=(int)$pdo->lastInsertId();}
    $assignment=sponsored_project_assignment($pdo,(int)$campaign['id'],(int)$viewer['id'])??[];
    if(function_exists('sponsored_project_compensation_ensure'))sponsored_project_compensation_ensure($pdo,$assignment,$campaign,(int)$viewer['id']);
    sponsored_project_event($pdo,(int)$campaign['id'],$id,null,(int)$viewer['id'],'research_agent_assigned',['agent_public_id'=>$agent['public_id'],'project_public_id'=>$agent['project_public_id'],'compensation_cents'=>(int)$campaign['researcher_compensation_cents'],'currency'=>$campaign['budget_currency']]);
    return $assignment;
}
function sponsored_project_asset_system_report(PDO $pdo,array $viewer,array $a,string $publicId): array {
    $r=research_system_report_access($pdo,$viewer,$publicId);if(!$r||(int)$r['research_agent_id']!==(int)$a['research_agent_id']||(string)$r['status']!=='ready')throw new RuntimeException('Choose a ready Report Run from the assigned Research Agent.');
    $snap=['schema'=>'annotated-sponsored-system-report-v1','public_id'=>$r['public_id'],'agent_public_id'=>$r['agent_public_id'],'project_public_id'=>$r['project_public_id'],'report_type'=>$r['report_type'],'title'=>$r['title'],'summary'=>$r['rendered_summary'],'input_state_hash'=>$r['input_state_hash'],'knowledge_manifest'=>$r['knowledge_manifest'],'sections'=>$r['sections'],'created_at'=>$r['created_at']];
    return ['type'=>'system_report','public_id'=>$r['public_id'],'version'=>(string)$r['input_state_hash'],'title'=>$r['title'],'snapshot'=>$snap,'hash'=>data_attribution_hash($snap),'document_public_id'=>$r['document_public_id']??null];
}
function sponsored_project_asset_document(PDO $pdo,array $viewer,array $a,string $publicId): array {
    $d=research_agent_workspace_object($pdo,$viewer,$publicId,false);if(!$d||$d['object_type']!=='document'||(int)$d['project_id']!==(int)$a['project_id'])throw new RuntimeException('Research Document is unavailable for this assigned Agent.');
    $snap=['schema'=>'annotated-sponsored-document-v1','public_id'=>$d['public_id'],'title'=>$d['title'],'document_type'=>$d['document_type'],'revision_number'=>(int)$d['revision_number'],'content_hash'=>$d['content_hash'],'summary'=>$d['document_summary'],'plain_text'=>$d['document_plain_text'],'created_by_agent'=>(int)$d['created_by_agent'],'updated_at'=>$d['updated_at']];
    return ['type'=>'document','public_id'=>$d['public_id'],'version'=>(string)$d['revision_number'],'title'=>$d['title'],'snapshot'=>$snap,'hash'=>data_attribution_hash($snap)];
}
function sponsored_project_asset_report_version(PDO $pdo,array $viewer,array $a,string $publicId): array {
    $q=$pdo->prepare("SELECT rv.*,rr.title report_title,rr.project_id FROM research_report_versions rv JOIN research_reports rr ON rr.id=rv.report_id WHERE rv.public_id=? LIMIT 1");$q->execute([$publicId]);$r=$q->fetch();if(!$r||(int)$r['project_id']!==(int)$a['project_id']||(int)$r['published_by_user_id']!==(int)$viewer['id'])throw new RuntimeException('Published Report version is unavailable for this assigned Agent.');
    $snap=json_decode((string)$r['snapshot_json'],true)?:[];return ['type'=>'report_version','public_id'=>$r['public_id'],'version'=>(string)$r['version_number'],'title'=>$r['report_title'],'snapshot'=>$snap,'hash'=>(string)$r['snapshot_hash']];
}
function sponsored_project_submit(PDO $pdo,array $viewer,string $campaignPublicId,array $assets,string $title='',string $summary='',bool $byAgent=true,string $supersedesPublicId=''): array {
    research_account_require_approved($pdo,$viewer);$campaign=sponsored_research_campaign_by_public($pdo,$campaignPublicId);if(!$campaign)throw new RuntimeException('Sponsored Project not found.');$a=sponsored_project_assignment($pdo,(int)$campaign['id'],(int)$viewer['id']);if(!$a||$a['status']!=='active'||empty($a['agent_submit_enabled']))throw new RuntimeException('An active assigned Research Agent is required.');
    $part=sponsored_research_participation_get($pdo,(int)$campaign['id'],(int)$viewer['id']);if(!$part||$part['status']!=='active'||(int)$part['campaign_revision_accepted']!==(int)$campaign['current_revision'])throw new RuntimeException('Current project terms must be accepted before submission.');
    $snaps=[];foreach($assets as $asset){$type=(string)($asset['type']??'');$id=trim((string)($asset['public_id']??''));if($id==='')continue;if($type==='system_report'){$s=sponsored_project_asset_system_report($pdo,$viewer,$a,$id);$snaps[]=$s;if(!empty($s['document_public_id']))$snaps[]=sponsored_project_asset_document($pdo,$viewer,$a,(string)$s['document_public_id']);}elseif($type==='document')$snaps[]=sponsored_project_asset_document($pdo,$viewer,$a,$id);elseif($type==='report_version')$snaps[]=sponsored_project_asset_report_version($pdo,$viewer,$a,$id);}
    if(!$snaps)throw new InvalidArgumentException('Attach at least one Report or Research Document.');
    $dedupe=[];$snaps=array_values(array_filter($snaps,function($x)use(&$dedupe){$k=$x['type'].'|'.$x['public_id'].'|'.$x['version'];if(isset($dedupe[$k]))return false;$dedupe[$k]=1;return true;}));
    $revisionNumber=1;$supersedesId=null;$supersedesPublicId=trim($supersedesPublicId);
    if($supersedesPublicId!==''){
        $previous=sponsored_project_submission_get($pdo,$supersedesPublicId);
        if(!$previous||(int)$previous['assignment_id']!==(int)$a['id']||(int)$previous['researcher_user_id']!==(int)$viewer['id'])throw new RuntimeException('Revision source is unavailable.');
        if((string)$previous['status']!=='revision_requested')throw new RuntimeException('A revision can only replace a submission with a pending revision request.');
        $q=$pdo->prepare('SELECT 1 FROM sponsored_research_project_submissions WHERE supersedes_submission_id=? LIMIT 1');$q->execute([(int)$previous['id']]);if($q->fetchColumn())throw new RuntimeException('This revision request has already been resubmitted.');
        $supersedesId=(int)$previous['id'];$revisionNumber=(int)($previous['revision_number']??1)+1;
    }
    $title=mb_substr(trim($title),0,255);if($title==='')$title=(string)($snaps[0]['title']??$campaign['title'].' submission');
    $core=['campaign_public_id'=>$campaign['public_id'],'assignment_public_id'=>$a['public_id'],'research_agent_public_id'=>$a['research_agent_public_id'],'researcher_user_id'=>(int)$viewer['id'],'revision_number'=>$revisionNumber,'supersedes_public_id'=>$supersedesPublicId!==''?$supersedesPublicId:null,'assets'=>array_map(fn($x)=>[$x['type'],$x['public_id'],$x['version'],$x['hash']],$snaps)];$hash=data_attribution_hash($core);
    $pdo->beginTransaction();try{
      $public=ulid_like();$pdo->prepare("INSERT INTO sponsored_research_project_submissions(public_id,assignment_id,campaign_id,researcher_user_id,research_agent_id,supersedes_submission_id,revision_number,title,summary,status,submitted_by_agent,submission_hash) VALUES(?,?,?,?,?,?,?,?,?,'submitted',?,?)")->execute([$public,(int)$a['id'],(int)$campaign['id'],(int)$viewer['id'],(int)$a['research_agent_id'],$supersedesId,$revisionNumber,$title,trim($summary)!==''?$summary:null,$byAgent?1:0,$hash]);$sid=(int)$pdo->lastInsertId();
      foreach($snaps as $pos=>$x)$pdo->prepare('INSERT INTO sponsored_research_project_submission_assets(public_id,project_submission_id,asset_type,asset_public_id,asset_version,title,snapshot_json,snapshot_hash,position) VALUES(?,?,?,?,?,?,?,?,?)')->execute([ulid_like(),$sid,$x['type'],$x['public_id'],$x['version'],$x['title'],data_attribution_encode($x['snapshot']),$x['hash'],$pos]);
      sponsored_project_event($pdo,(int)$campaign['id'],(int)$a['id'],$sid,(int)$viewer['id'],$supersedesId?'project_submission_resubmitted':'project_submission_created',['submission_hash'=>$hash,'asset_count'=>count($snaps),'submitted_by_agent'=>$byAgent,'revision_number'=>$revisionNumber,'supersedes_public_id'=>$supersedesPublicId!==''?$supersedesPublicId:null]);
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if(function_exists('notification_create'))notification_create($pdo,(int)$viewer['id'],(int)$viewer['id'],$supersedesId?'research_sponsored_resubmitted':'research_sponsored_submitted','sponsored_project_submission',$public,$supersedesId?'Sponsored Project revision submitted.':'Sponsored Project submission received.',['allow_self'=>true,'category'=>'research','dedupe_key'=>'sponsored-submit:'.$public]);
    sponsored_project_notify_admins($pdo,(int)$viewer['id'],$supersedesId?'research_sponsored_resubmitted':'research_sponsored_submitted',$public,$supersedesId?'A Sponsored Project revision was submitted.':'A Sponsored Project submission was received.',['campaign_public_id'=>$campaign['public_id'],'research_agent_public_id'=>$a['research_agent_public_id']]);
    return sponsored_project_submission_get($pdo,$public)??[];
}
function sponsored_project_submission_get(PDO $pdo,string $publicId): ?array {$q=$pdo->prepare("SELECT s.*,c.public_id campaign_public_id,c.title campaign_title,u.display_name researcher_name,u.username researcher_username,ra.public_id agent_public_id,ra.name agent_name,prev.public_id supersedes_public_id,rv.display_name reviewer_name FROM sponsored_research_project_submissions s JOIN sponsored_research_campaigns c ON c.id=s.campaign_id JOIN users u ON u.id=s.researcher_user_id JOIN research_agents ra ON ra.id=s.research_agent_id LEFT JOIN sponsored_research_project_submissions prev ON prev.id=s.supersedes_submission_id LEFT JOIN users rv ON rv.id=s.reviewed_by_user_id WHERE s.public_id=? LIMIT 1");$q->execute([$publicId]);$s=$q->fetch();if(!$s)return null;$q=$pdo->prepare('SELECT * FROM sponsored_research_project_submission_assets WHERE project_submission_id=? ORDER BY position,id');$q->execute([(int)$s['id']]);$s['assets']=$q->fetchAll()?:[];return $s;}
function sponsored_project_user_submissions(PDO $pdo,int $userId,int $limit=100): array {$q=$pdo->prepare("SELECT s.public_id FROM sponsored_research_project_submissions s WHERE s.researcher_user_id=? ORDER BY s.id DESC LIMIT ".max(1,min(250,$limit)));$q->execute([$userId]);return array_values(array_filter(array_map(fn($p)=>sponsored_project_submission_get($pdo,(string)$p),$q->fetchAll(PDO::FETCH_COLUMN)?:[])));}
function sponsored_project_agent_reports(PDO $pdo,array $viewer,array $a): array {return research_system_report_list($pdo,$viewer,(string)$a['research_agent_public_id'],100);}
function sponsored_project_admin_projects(PDO $pdo): array {$q=$pdo->query("SELECT c.public_id,c.title,c.status,c.budget_cents,c.budget_currency,c.researcher_compensation_cents,
      (SELECT COUNT(*) FROM sponsored_research_agent_assignments a WHERE a.campaign_id=c.id AND a.status IN ('active','paused','completed')) assigned_agents,
      (SELECT COUNT(*) FROM sponsored_research_project_submissions s WHERE s.campaign_id=c.id AND s.status<>'withdrawn') submissions,
      (SELECT COALESCE(SUM(pc.amount_cents),0) FROM sponsored_research_project_compensations pc WHERE pc.campaign_id=c.id AND pc.status<>'voided') compensation_total_cents,
      (SELECT COALESCE(SUM(pc.amount_cents),0) FROM sponsored_research_project_compensations pc WHERE pc.campaign_id=c.id AND pc.status='earned') compensation_earned_cents,
      (SELECT COALESCE(SUM(pc.amount_cents),0) FROM sponsored_research_project_compensations pc WHERE pc.campaign_id=c.id AND pc.status='approved_for_payment') compensation_approved_cents,
      (SELECT COALESCE(SUM(pc.amount_cents),0) FROM sponsored_research_project_compensations pc WHERE pc.campaign_id=c.id AND pc.status='paid') compensation_paid_cents
      FROM sponsored_research_campaigns c ORDER BY c.created_at DESC");return $q->fetchAll()?:[];}
function sponsored_project_admin_assignments(PDO $pdo,int $campaignId): array {$q=$pdo->prepare("SELECT a.*,u.display_name researcher_name,u.username researcher_username,ra.public_id agent_public_id,ra.name agent_name FROM sponsored_research_agent_assignments a JOIN users u ON u.id=a.researcher_user_id JOIN research_agents ra ON ra.id=a.research_agent_id WHERE a.campaign_id=? ORDER BY a.id DESC");$q->execute([$campaignId]);$rows=$q->fetchAll()?:[];foreach($rows as &$row)$row['compensation']=function_exists('sponsored_project_compensation_for_assignment')?sponsored_project_compensation_for_assignment($pdo,(int)$row['id']):null;unset($row);return $rows;}
function sponsored_project_admin_submissions(PDO $pdo,int $campaignId): array {$q=$pdo->prepare("SELECT public_id FROM sponsored_research_project_submissions WHERE campaign_id=? ORDER BY submitted_at DESC,id DESC");$q->execute([$campaignId]);return array_values(array_filter(array_map(fn($p)=>sponsored_project_submission_get($pdo,(string)$p),$q->fetchAll(PDO::FETCH_COLUMN)?:[])));}
function sponsored_project_admin_status(PDO $pdo,array $admin,string $submissionPublicId,string $status,string $note=''): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required.');
    if(!in_array($status,['under_review','accepted','revision_requested','rejected'],true))throw new InvalidArgumentException('Invalid submission status.');
    $s=sponsored_project_submission_get($pdo,$submissionPublicId);if(!$s)throw new RuntimeException('Submission not found.');
    $from=(string)$s['status'];$allowed=['submitted'=>['under_review','accepted','revision_requested','rejected'],'under_review'=>['under_review','accepted','revision_requested','rejected']];
    if(!in_array($status,$allowed[$from]??[],true))throw new RuntimeException('This submission decision is already final or superseded.');
    $note=mb_substr(trim($note),0,12000);if($status==='revision_requested'&&$note==='')throw new InvalidArgumentException('Revision instructions are required.');
    $decision=in_array($status,['accepted','revision_requested','rejected'],true);
    $pdo->beginTransaction();try{
      $pdo->prepare('UPDATE sponsored_research_project_submissions SET status=?,reviewed_at=NOW(),review_note=?,reviewed_by_user_id=?,decision_at=? WHERE id=?')->execute([$status,$note!==''?$note:null,(int)$admin['id'],$decision?date('Y-m-d H:i:s'):null,(int)$s['id']]);
      if($status==='accepted'){$pdo->prepare("UPDATE sponsored_research_agent_assignments SET status='completed',completed_submission_id=?,completed_at=NOW() WHERE id=?")->execute([(int)$s['id'],(int)$s['assignment_id']]);if(function_exists('sponsored_project_compensation_mark_earned'))sponsored_project_compensation_mark_earned($pdo,$s,$admin);}
      sponsored_project_event($pdo,(int)$s['campaign_id'],(int)$s['assignment_id'],(int)$s['id'],(int)$admin['id'],'project_submission_'.$status,['from_status'=>$from,'to_status'=>$status,'review_note'=>$note!==''?$note:null,'revision_number'=>(int)($s['revision_number']??1)]);
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if(function_exists('notification_create')){
      $body=match($status){'under_review'=>'Your Sponsored Project submission is under review.','accepted'=>'Your Sponsored Project submission was accepted.','revision_requested'=>'A revision was requested for your Sponsored Project submission.','rejected'=>'Your Sponsored Project submission was rejected.',default=>'Sponsored Project submission updated.'};
      notification_create($pdo,(int)$s['researcher_user_id'],(int)$admin['id'],'research_sponsored_'.$status,'sponsored_project_submission',$submissionPublicId,$body,['category'=>'research','dedupe_key'=>'sponsored-review:'.$submissionPublicId.':'.$status,'context'=>['campaign_public_id'=>$s['campaign_public_id'],'research_agent_public_id'=>$s['agent_public_id']]]);
    }
    return sponsored_project_submission_get($pdo,$submissionPublicId)??[];
}
function sponsored_project_revision_chain(PDO $pdo,string $submissionPublicId): array {
    $s=sponsored_project_submission_get($pdo,$submissionPublicId);if(!$s)return [];
    $q=$pdo->prepare('SELECT public_id FROM sponsored_research_project_submissions WHERE assignment_id=? ORDER BY revision_number ASC,id ASC');$q->execute([(int)$s['assignment_id']]);
    return array_values(array_filter(array_map(fn($p)=>sponsored_project_submission_get($pdo,(string)$p),$q->fetchAll(PDO::FETCH_COLUMN)?:[])));
}
function sponsored_project_agent_context(PDO $pdo,int $researchAgentId): ?array {
    $q=$pdo->prepare("SELECT a.*,c.public_id campaign_public_id,c.title campaign_title,s.public_id latest_submission_public_id,s.status latest_submission_status,s.review_note,s.revision_number FROM sponsored_research_agent_assignments a JOIN sponsored_research_campaigns c ON c.id=a.campaign_id LEFT JOIN sponsored_research_project_submissions s ON s.id=(SELECT s2.id FROM sponsored_research_project_submissions s2 WHERE s2.assignment_id=a.id ORDER BY s2.revision_number DESC,s2.id DESC LIMIT 1) WHERE a.research_agent_id=? AND a.status IN ('active','completed') ORDER BY a.id DESC LIMIT 1");
    $q->execute([$researchAgentId]);$r=$q->fetch();if(!$r)return null;
    $r['requires_revision']=(string)($r['latest_submission_status']??'')==='revision_requested';
    $r['completed']=(string)$r['status']==='completed'&&!empty($r['completed_submission_id']);
    return $r;
}
