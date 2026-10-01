<?php
declare(strict_types=1);

function sponsored_research_participation_ready(PDO $pdo): bool {
    try{
        return sponsored_research_campaigns_ready($pdo)
          && installer_table_exists($pdo,'sponsored_research_campaign_invites')
          && installer_table_exists($pdo,'sponsored_research_campaign_terms')
          && installer_table_exists($pdo,'sponsored_research_participations')
          && installer_table_exists($pdo,'sponsored_research_participation_events');
    }catch(Throwable $e){return false;}
}
function sponsored_research_verification_rank(string $level): int {
    return ['basic'=>1,'identity'=>2,'qualification'=>3,'organization'=>4,'payout'=>5][$level]??0;
}
function sponsored_research_participation_event(PDO $pdo,int $campaignId,?int $participationId,?int $researcherUserId,?int $actorUserId,string $eventType,array $payload=[]): void {
    $pdo->prepare('INSERT INTO sponsored_research_participation_events(public_id,campaign_id,participation_id,researcher_user_id,actor_user_id,event_type,payload_json) VALUES(?,?,?,?,?,?,?)')
      ->execute([ulid_like(),$campaignId,$participationId,$researcherUserId,$actorUserId,mb_substr($eventType,0,80),$payload?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):null]);
}
function sponsored_research_campaign_terms_latest(PDO $pdo,int $campaignId): ?array {
    if(!sponsored_research_participation_ready($pdo))return null;$q=$pdo->prepare('SELECT * FROM sponsored_research_campaign_terms WHERE campaign_id=? ORDER BY version_number DESC LIMIT 1');$q->execute([$campaignId]);return $q->fetch()?:null;
}
function sponsored_research_campaign_terms_publish(PDO $pdo,array $viewer,string $campaignPublicId,string $termsText,string $reason='Campaign participation terms updated.'): array {
    $campaign=sponsored_research_campaign_require_manage($pdo,$viewer,$campaignPublicId);$termsText=trim($termsText);if($termsText==='')throw new InvalidArgumentException('Participation terms are required.');
    return app_with_advisory_lock($pdo,'sponsored-research-terms',(int)$campaign['id'],function() use($pdo,$viewer,$campaign,$termsText,$reason){
        $current=sponsored_research_campaign_terms_latest($pdo,(int)$campaign['id']);$next=(int)($current['version_number']??0)+1;$disclosures=(array)($campaign['disclosures']??[]);
        $hash=hash('sha256',$termsText);
        $pdo->prepare('INSERT INTO sponsored_research_campaign_terms(public_id,campaign_id,version_number,campaign_revision,terms_text,terms_hash,requires_conflict_disclosure,requires_nda,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?)')
          ->execute([ulid_like(),(int)$campaign['id'],$next,(int)$campaign['current_revision'],$termsText,$hash,!empty($disclosures['conflict_disclosure_required'])?1:0,!empty($disclosures['nda_required'])?1:0,(int)$viewer['id']]);
        $row=sponsored_research_campaign_terms_latest($pdo,(int)$campaign['id']);
        sponsored_research_participation_event($pdo,(int)$campaign['id'],null,null,(int)$viewer['id'],'terms_published',['terms_version'=>$next,'campaign_revision'=>(int)$campaign['current_revision'],'terms_hash'=>$hash,'reason'=>mb_substr(trim($reason),0,1000)]);
        return $row??[];
    },5);
}
function sponsored_research_campaign_invite(PDO $pdo,array $viewer,string $campaignPublicId,string $usernameOrEmail,?string $expiresAt=null): array {
    $campaign=sponsored_research_campaign_require_manage($pdo,$viewer,$campaignPublicId);$needle=trim($usernameOrEmail);if($needle==='')throw new InvalidArgumentException('Researcher username or email is required.');
    $q=$pdo->prepare('SELECT * FROM users WHERE username=? OR email=? LIMIT 1');$q->execute([$needle,strtolower($needle)]);$researcher=$q->fetch();if(!$researcher)throw new RuntimeException('Researcher account not found.');
    if(!research_account_is_approved($pdo,$researcher))throw new RuntimeException('Only approved Research Accounts can be invited.');
    $expires=sponsored_research_campaign_datetime($expiresAt??'','Invite expiration');
    $pdo->prepare("INSERT INTO sponsored_research_campaign_invites(public_id,campaign_id,invited_user_id,invited_by_user_id,status,expires_at) VALUES(?,?,?,?, 'pending',?)
      ON DUPLICATE KEY UPDATE invited_by_user_id=VALUES(invited_by_user_id),status='pending',expires_at=VALUES(expires_at),accepted_at=NULL,declined_at=NULL,revoked_at=NULL,updated_at=NOW()")
      ->execute([ulid_like(),(int)$campaign['id'],(int)$researcher['id'],(int)$viewer['id'],$expires]);
    $q=$pdo->prepare('SELECT * FROM sponsored_research_campaign_invites WHERE campaign_id=? AND invited_user_id=?');$q->execute([(int)$campaign['id'],(int)$researcher['id']]);$invite=$q->fetch()?:[];
    sponsored_research_participation_event($pdo,(int)$campaign['id'],null,(int)$researcher['id'],(int)$viewer['id'],'researcher_invited',['invite_public_id'=>$invite['public_id']??null,'expires_at'=>$expires]);
    return $invite;
}
function sponsored_research_campaign_invite_for(PDO $pdo,int $campaignId,int $userId): ?array {
    $q=$pdo->prepare("SELECT * FROM sponsored_research_campaign_invites WHERE campaign_id=? AND invited_user_id=? LIMIT 1");$q->execute([$campaignId,$userId]);$row=$q->fetch();if(!$row)return null;
    if($row['status']==='pending'&&!empty($row['expires_at'])&&strtotime((string)$row['expires_at'])<=time()){
        $pdo->prepare("UPDATE sponsored_research_campaign_invites SET status='expired',updated_at=NOW() WHERE id=? AND status='pending'")->execute([(int)$row['id']]);$row['status']='expired';
    }
    return $row;
}
function sponsored_research_campaign_completed_count(PDO $pdo,int $userId): int {
    $q=$pdo->prepare("SELECT COUNT(*) FROM sponsored_research_participations WHERE researcher_user_id=? AND status='completed'");$q->execute([$userId]);return (int)$q->fetchColumn();
}
function sponsored_research_campaign_eligibility_check(PDO $pdo,array $researcher,array $campaign): array {
    $profile=research_account_profile($pdo,(int)$researcher['id']);$reasons=[];$eligible=true;
    if(!$profile||!research_account_is_approved($pdo,$researcher)){$eligible=false;$reasons[]='approved_research_account_required';}
    $rules=(array)($campaign['eligibility']??[]);
    $required=(string)($rules['min_verification']??'basic');$actual=(string)($profile['verification_level']??'');
    if($profile&&sponsored_research_verification_rank($actual)<sponsored_research_verification_rank($required)){$eligible=false;$reasons[]='verification_level';}
    $specialties=array_map('mb_strtolower',(array)($profile['specialties']??[]));foreach((array)($rules['specialties']??[]) as $requiredSpecialty){if(!in_array(mb_strtolower((string)$requiredSpecialty),$specialties,true)){$eligible=false;$reasons[]='specialty:'.(string)$requiredSpecialty;}}
    $languages=array_map('mb_strtolower',(array)($profile['languages']??[]));foreach((array)($rules['languages']??[]) as $requiredLanguage){if(!in_array(mb_strtolower((string)$requiredLanguage),$languages,true)){$eligible=false;$reasons[]='language:'.(string)$requiredLanguage;}}
    $completed=sponsored_research_campaign_completed_count($pdo,(int)$researcher['id']);$minCompleted=(int)($rules['min_completed_campaigns']??0);if($completed<$minCompleted){$eligible=false;$reasons[]='completed_campaigns';}
    $invite=sponsored_research_campaign_invite_for($pdo,(int)$campaign['id'],(int)$researcher['id']);$mode=(string)$campaign['access_mode'];
    if($mode==='invite_only'&&(!$invite||$invite['status']!=='pending')){$eligible=false;$reasons[]='invite_required';}
    if($mode==='private'&&(!$invite||!in_array((string)$invite['status'],['pending','accepted'],true))){$eligible=false;$reasons[]='private_access_required';}
    return [
      'eligible'=>$eligible,
      'reasons'=>array_values(array_unique($reasons)),
      'research_account_status'=>$profile['status']??'not_applied',
      'verification_level'=>$actual?:null,
      'required_verification'=>$required,
      'completed_campaigns'=>$completed,
      'required_completed_campaigns'=>$minCompleted,
      'invite_public_id'=>$invite['public_id']??null,
      'invite_status'=>$invite['status']??null,
      'campaign_revision'=>(int)$campaign['current_revision']
    ];
}
function sponsored_research_campaign_visible_to_researcher(PDO $pdo,array $researcher,array $campaign): bool {
    if((string)$campaign['status']!=='open')return false;
    if(!empty($campaign['starts_at'])&&strtotime((string)$campaign['starts_at'])>time())return false;
    if(!empty($campaign['submission_deadline'])&&strtotime((string)$campaign['submission_deadline'])<=time())return false;
    if((string)$campaign['access_mode']==='public')return true;
    $invite=sponsored_research_campaign_invite_for($pdo,(int)$campaign['id'],(int)$researcher['id']);
    return $invite&&in_array((string)$invite['status'],['pending','accepted'],true);
}
function sponsored_research_campaign_opportunities(PDO $pdo,array $researcher,int $limit=100): array {
    research_account_require_approved($pdo,$researcher);$limit=max(1,min(300,$limit));
    $q=$pdo->query("SELECT c.*,a.name account_name,sp.organization_name,ra.public_id research_agent_public_id,ra.name research_agent_name
      FROM sponsored_research_campaigns c JOIN accounts a ON a.id=c.account_id JOIN sponsor_account_profiles sp ON sp.id=c.sponsor_profile_id
      LEFT JOIN research_agents ra ON ra.id=c.research_agent_id
      WHERE c.status='open' ORDER BY c.submission_deadline IS NULL,c.submission_deadline,c.updated_at DESC LIMIT ".$limit);
    $rows=[];foreach($q->fetchAll()?:[] as $row){$row['eligibility']=sponsored_research_campaign_json($row['eligibility_json']??null);$row['disclosures']=sponsored_research_campaign_json($row['disclosure_json']??null);if(!sponsored_research_campaign_visible_to_researcher($pdo,$researcher,$row))continue;$row['eligibility_result']=sponsored_research_campaign_eligibility_check($pdo,$researcher,$row);$rows[]=$row;}return $rows;
}
function sponsored_research_participation_get(PDO $pdo,int $campaignId,int $userId): ?array {
    $q=$pdo->prepare("SELECT p.*,t.version_number terms_version,t.terms_hash,t.terms_text FROM sponsored_research_participations p JOIN sponsored_research_campaign_terms t ON t.id=p.terms_id WHERE p.campaign_id=? AND p.researcher_user_id=? LIMIT 1");$q->execute([$campaignId,$userId]);$row=$q->fetch();if(!$row)return null;$row['eligibility_snapshot']=sponsored_research_campaign_json($row['eligibility_snapshot_json']??null);return $row;
}
function sponsored_research_campaign_join(PDO $pdo,array $researcher,string $campaignPublicId,array $input): array {
    research_account_require_approved($pdo,$researcher);$campaign=sponsored_research_campaign_by_public($pdo,$campaignPublicId);if(!$campaign||!sponsored_research_campaign_visible_to_researcher($pdo,$researcher,$campaign))throw new RuntimeException('This Sponsored Research campaign is not available to you.');
    return app_with_advisory_lock($pdo,'sponsored-research-participation',(int)$campaign['id'],function() use($pdo,$researcher,$campaign,$input){
        $fresh=sponsored_research_campaign_by_public($pdo,(string)$campaign['public_id']);if(!$fresh||!sponsored_research_campaign_visible_to_researcher($pdo,$researcher,$fresh))throw new RuntimeException('This Sponsored Research campaign is no longer available.');
        $eligibility=sponsored_research_campaign_eligibility_check($pdo,$researcher,$fresh);if(empty($eligibility['eligible']))throw new RuntimeException('You do not meet this campaign’s eligibility requirements.');
        $existing=sponsored_research_participation_get($pdo,(int)$fresh['id'],(int)$researcher['id']);if($existing&&$existing['status']==='active')return $existing;
        $terms=sponsored_research_campaign_terms_latest($pdo,(int)$fresh['id']);if(!$terms)throw new RuntimeException('Campaign participation terms have not been published.');
        if((int)$terms['campaign_revision']!==(int)$fresh['current_revision'])throw new RuntimeException('Campaign terms must be republished for the current campaign revision.');
        if(empty($input['accept_terms']))throw new InvalidArgumentException('You must accept the campaign participation terms.');
        $disclosures=(array)($fresh['disclosures']??[]);$conflict=trim((string)($input['conflict_disclosure']??''));
        if(!empty($terms['requires_conflict_disclosure'])&&$conflict==='')throw new InvalidArgumentException('A conflict-of-interest disclosure is required.');
        $nda=!empty($input['accept_nda']);if(!empty($terms['requires_nda'])&&!$nda)throw new InvalidArgumentException('You must accept the campaign NDA.');
        $sponsorAck=!empty($input['acknowledge_sponsorship']);if(!empty($disclosures['sponsorship_disclosure_required'])&&!$sponsorAck)throw new InvalidArgumentException('You must acknowledge the sponsorship disclosure.');
        $q=$pdo->prepare("SELECT COUNT(*) FROM sponsored_research_participations WHERE campaign_id=? AND status='active'");$q->execute([(int)$fresh['id']]);$active=(int)$q->fetchColumn();if($fresh['max_participants']!==null&&$active>=(int)$fresh['max_participants'])throw new RuntimeException('This campaign has reached its participant limit.');
        $profile=research_account_profile($pdo,(int)$researcher['id']);$invite=sponsored_research_campaign_invite_for($pdo,(int)$fresh['id'],(int)$researcher['id']);$public=ulid_like();
        $pdo->beginTransaction();try{
          if($existing){
            $pdo->prepare("UPDATE sponsored_research_participations SET research_profile_id=?,invite_id=?,status='active',campaign_revision_accepted=?,terms_id=?,eligibility_snapshot_json=?,conflict_disclosure=?,nda_accepted=?,sponsorship_disclosure_acknowledged=?,joined_at=NOW(),withdrawn_at=NULL,removed_at=NULL,completed_at=NULL,updated_at=NOW() WHERE id=?")
              ->execute([(int)$profile['id'],$invite['id']??null,(int)$fresh['current_revision'],(int)$terms['id'],json_encode($eligibility,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$conflict!==''?$conflict:null,$nda?1:0,$sponsorAck?1:0,(int)$existing['id']]);$participationId=(int)$existing['id'];
          }else{
            $pdo->prepare("INSERT INTO sponsored_research_participations(public_id,campaign_id,researcher_user_id,research_profile_id,invite_id,status,campaign_revision_accepted,terms_id,eligibility_snapshot_json,conflict_disclosure,nda_accepted,sponsorship_disclosure_acknowledged) VALUES(?,?,?,?,?,'active',?,?,?,?,?,?)")
              ->execute([$public,(int)$fresh['id'],(int)$researcher['id'],(int)$profile['id'],$invite['id']??null,(int)$fresh['current_revision'],(int)$terms['id'],json_encode($eligibility,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$conflict!==''?$conflict:null,$nda?1:0,$sponsorAck?1:0]);$participationId=(int)$pdo->lastInsertId();
          }
          if($invite&&$invite['status']==='pending')$pdo->prepare("UPDATE sponsored_research_campaign_invites SET status='accepted',accepted_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$invite['id']]);
          sponsored_research_participation_event($pdo,(int)$fresh['id'],$participationId,(int)$researcher['id'],(int)$researcher['id'],'participation_joined',['campaign_revision'=>(int)$fresh['current_revision'],'terms_version'=>(int)$terms['version_number'],'terms_hash'=>$terms['terms_hash'],'training_consent_granted'=>false]);
          $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return sponsored_research_participation_get($pdo,(int)$fresh['id'],(int)$researcher['id'])??[];
    },5);
}
function sponsored_research_campaign_withdraw(PDO $pdo,array $researcher,string $campaignPublicId,string $reason=''): array {
    $campaign=sponsored_research_campaign_by_public($pdo,$campaignPublicId);if(!$campaign)throw new RuntimeException('Campaign not found.');$participation=sponsored_research_participation_get($pdo,(int)$campaign['id'],(int)$researcher['id']);if(!$participation||$participation['status']!=='active')throw new RuntimeException('You do not have an active participation in this campaign.');
    $pdo->prepare("UPDATE sponsored_research_participations SET status='withdrawn',withdrawn_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$participation['id']]);
    sponsored_research_participation_event($pdo,(int)$campaign['id'],(int)$participation['id'],(int)$researcher['id'],(int)$researcher['id'],'participation_withdrawn',['reason'=>mb_substr(trim($reason),0,1000)]);
    return sponsored_research_participation_get($pdo,(int)$campaign['id'],(int)$researcher['id'])??[];
}
function sponsored_research_campaign_participants(PDO $pdo,array $viewer,string $campaignPublicId,int $limit=200): array {
    $campaign=sponsored_research_campaign_require_manage($pdo,$viewer,$campaignPublicId);$q=$pdo->prepare("SELECT p.*,u.username,u.display_name,r.verification_level,t.version_number terms_version FROM sponsored_research_participations p JOIN users u ON u.id=p.researcher_user_id JOIN research_account_profiles r ON r.id=p.research_profile_id JOIN sponsored_research_campaign_terms t ON t.id=p.terms_id WHERE p.campaign_id=? ORDER BY p.joined_at DESC LIMIT ".max(1,min(500,$limit)));$q->execute([(int)$campaign['id']]);return $q->fetchAll()?:[];
}
