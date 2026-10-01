<?php
declare(strict_types=1);

function sponsored_research_campaigns_ready(PDO $pdo): bool {
    try{
        return research_accounts_ready($pdo)
          && installer_table_exists($pdo,'sponsored_research_campaigns')
          && installer_table_exists($pdo,'sponsored_research_campaign_questions')
          && installer_table_exists($pdo,'sponsored_research_campaign_versions')
          && installer_table_exists($pdo,'sponsored_research_campaign_events');
    }catch(Throwable $e){return false;}
}
function sponsored_research_campaign_statuses(): array {
    return ['draft'=>'Draft','funding_required'=>'Funding required','scheduled'=>'Scheduled','open'=>'Open','paused'=>'Paused','closed'=>'Closed','under_review'=>'Under review','completed'=>'Completed','cancelled'=>'Cancelled','archived'=>'Archived'];
}
function sponsored_research_campaign_access_modes(): array {
    return ['public'=>'Public','private'=>'Private','invite_only'=>'Invite only'];
}
function sponsored_research_campaign_json(mixed $value): array {
    if(is_array($value))return $value;
    if(!is_string($value)||trim($value)==='')return [];
    try{$v=json_decode($value,true,32,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(Throwable $e){return [];}
}
function sponsored_research_campaign_list_clean(mixed $input,int $limit=30): array {
    if(is_string($input))$input=preg_split('/[,\n]+/',$input)?:[];
    if(!is_array($input))return [];
    $out=[];foreach($input as $item){$item=mb_substr(trim((string)$item),0,120);if($item===''||in_array($item,$out,true))continue;$out[]=$item;if(count($out)>=$limit)break;}return $out;
}
function sponsored_research_campaign_eligibility(mixed $input): array {
    $value=sponsored_research_campaign_json($input);$levels=['basic','identity','qualification','organization','payout'];
    $level=(string)($value['min_verification']??'basic');if(!in_array($level,$levels,true))$level='basic';
    return ['min_verification'=>$level,'specialties'=>sponsored_research_campaign_list_clean($value['specialties']??[]),'languages'=>sponsored_research_campaign_list_clean($value['languages']??[]),'min_completed_campaigns'=>max(0,min(10000,(int)($value['min_completed_campaigns']??0)))];
}
function sponsored_research_campaign_disclosures(mixed $input): array {
    $value=sponsored_research_campaign_json($input);$ai=(string)($value['ai_assistance_policy']??'allowed_with_disclosure');if(!in_array($ai,['unrestricted','allowed_with_disclosure','restricted','prohibited'],true))$ai='allowed_with_disclosure';
    $training=(string)($value['training_use_request']??'none');if(!in_array($training,['none','optional_separate_consent'],true))$training='none';
    return ['sponsorship_disclosure_required'=>array_key_exists('sponsorship_disclosure_required',$value)?!empty($value['sponsorship_disclosure_required']):true,'conflict_disclosure_required'=>array_key_exists('conflict_disclosure_required',$value)?!empty($value['conflict_disclosure_required']):true,'nda_required'=>!empty($value['nda_required']),'ai_assistance_policy'=>$ai,'training_use_request'=>$training];
}
function sponsored_research_campaign_datetime(mixed $value,string $label): ?string {
    $value=trim((string)$value);if($value==='')return null;
    $formats=['Y-m-d\\TH:i','Y-m-d H:i:s','Y-m-d H:i'];$dt=null;
    foreach($formats as $format){$candidate=DateTimeImmutable::createFromFormat('!'.$format,$value,new DateTimeZone('UTC'));$errors=DateTimeImmutable::getLastErrors();if($candidate&&($errors===false||((int)$errors['warning_count']===0&&(int)$errors['error_count']===0))){$dt=$candidate;break;}}
    if(!$dt)throw new InvalidArgumentException($label.' is invalid.');
    return $dt->format('Y-m-d H:i:s');
}
function sponsored_research_campaign_money_cents(mixed $value): int {
    $value=trim((string)$value);if($value==='')return 0;
    if(!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/',$value))throw new InvalidArgumentException('Budget must be a non-negative amount with up to two decimal places.');
    [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');$fraction=str_pad($fraction,2,'0');
    $cents=((int)$whole*100)+(int)substr($fraction,0,2);if($cents<0)throw new InvalidArgumentException('Budget cannot be negative.');return $cents;
}
function sponsored_research_campaign_questions_clean(mixed $input): array {
    if(is_string($input))$input=preg_split('/\r?\n+/',$input)?:[];
    if(!is_array($input))return [];
    $out=[];foreach($input as $q){$q=trim((string)$q);if($q==='')continue;$out[]=mb_substr($q,0,4000);if(count($out)>=50)break;}return $out;
}
function sponsored_research_campaign_account(PDO $pdo,array $viewer,string $accountPublicId): array {
    sponsor_account_require_approved($pdo,$viewer);
    $account=account_membership_account_for_user($pdo,$viewer,$accountPublicId,true);
    if((string)($account['status']??'')!=='active')throw new RuntimeException('Sponsored Research requires an active commercial account.');
    return $account;
}
function sponsored_research_campaign_agent(PDO $pdo,array $viewer,?string $agentPublic): ?array {
    $agentPublic=trim((string)$agentPublic);if($agentPublic==='')return null;
    $agent=research_agent_access($pdo,$viewer,$agentPublic);if(!$agent)throw new RuntimeException('Research Agent not found.');
    $canEdit=(int)$agent['owner_user_id']===(int)$viewer['id']||in_array((string)($agent['team_role']??''),['owner','admin'],true);
    if(!$canEdit)throw new RuntimeException('You do not administer that Research Agent.');
    return $agent;
}
function sponsored_research_campaign_config(array $campaign,array $questions): array {
    return [
      'title'=>(string)$campaign['title'],
      'brief'=>(string)$campaign['brief'],
      'objective'=>(string)$campaign['objective'],
      'status'=>(string)$campaign['status'],
      'access_mode'=>(string)$campaign['access_mode'],
      'budget_currency'=>(string)$campaign['budget_currency'],
      'budget_cents'=>(int)$campaign['budget_cents'],
      'max_participants'=>$campaign['max_participants']===null?null:(int)$campaign['max_participants'],
      'eligibility'=>sponsored_research_campaign_json($campaign['eligibility_json']??null),
      'disclosures'=>sponsored_research_campaign_json($campaign['disclosure_json']??null),
      'starts_at'=>$campaign['starts_at']??null,
      'submission_deadline'=>$campaign['submission_deadline']??null,
      'review_deadline'=>$campaign['review_deadline']??null,
      'research_agent_public_id'=>$campaign['research_agent_public_id']??null,
      'questions'=>array_values(array_map(fn($q)=>(string)$q['question'],$questions))
    ];
}
function sponsored_research_campaign_hash(array $config): string {
    return hash('sha256',json_encode($config,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function sponsored_research_campaign_event(PDO $pdo,int $campaignId,?int $actorUserId,string $eventType,array $payload=[]): void {
    $pdo->prepare('INSERT INTO sponsored_research_campaign_events(public_id,campaign_id,actor_user_id,event_type,payload_json) VALUES(?,?,?,?,?)')
      ->execute([ulid_like(),$campaignId,$actorUserId,mb_substr($eventType,0,80),$payload?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):null]);
}
function sponsored_research_campaign_questions(PDO $pdo,int $campaignId): array {
    $q=$pdo->prepare('SELECT * FROM sponsored_research_campaign_questions WHERE campaign_id=? ORDER BY position,id');$q->execute([$campaignId]);return $q->fetchAll()?:[];
}
function sponsored_research_campaign_by_public(PDO $pdo,string $publicId): ?array {
    if(!sponsored_research_campaigns_ready($pdo))return null;
    $q=$pdo->prepare("SELECT c.*,a.public_id account_public_id,a.name account_name,sp.public_id sponsor_profile_public_id,sp.organization_name,
      ra.public_id research_agent_public_id,ra.name research_agent_name,u.username sponsor_username,u.display_name sponsor_display_name
      FROM sponsored_research_campaigns c
      JOIN accounts a ON a.id=c.account_id
      JOIN sponsor_account_profiles sp ON sp.id=c.sponsor_profile_id
      JOIN users u ON u.id=c.sponsor_user_id
      LEFT JOIN research_agents ra ON ra.id=c.research_agent_id
      WHERE c.public_id=? LIMIT 1");
    $q->execute([trim($publicId)]);$row=$q->fetch();if(!$row)return null;
    $row['questions']=sponsored_research_campaign_questions($pdo,(int)$row['id']);
    $row['eligibility']=sponsored_research_campaign_json($row['eligibility_json']??null);
    $row['disclosures']=sponsored_research_campaign_json($row['disclosure_json']??null);
    return $row;
}
function sponsored_research_campaign_require_manage(PDO $pdo,array $viewer,string $publicId): array {
    $campaign=sponsored_research_campaign_by_public($pdo,$publicId);if(!$campaign)throw new RuntimeException('Sponsored Research campaign not found.');
    sponsor_account_require_approved($pdo,$viewer);
    $account=account_membership_account_for_user($pdo,$viewer,(string)$campaign['account_public_id'],true);
    account_membership_require_manage($pdo,$viewer,$account);
    if((string)($account['status']??'')!=='active')throw new RuntimeException('Sponsored Research requires an active commercial account.');
    return $campaign;
}
function sponsored_research_campaign_locked(PDO $pdo,array $viewer,string $publicId,callable $callback): mixed {
    $seed=sponsored_research_campaign_require_manage($pdo,$viewer,$publicId);
    return app_with_advisory_lock($pdo,'sponsored-research-campaign',(int)$seed['id'],function() use($pdo,$viewer,$publicId,$callback){
        $fresh=sponsored_research_campaign_require_manage($pdo,$viewer,$publicId);
        return $callback($fresh);
    },5);
}
function sponsored_research_campaign_snapshot(PDO $pdo,array $campaign,array $viewer,string $reason): array {
    $questions=sponsored_research_campaign_questions($pdo,(int)$campaign['id']);$config=sponsored_research_campaign_config($campaign,$questions);$hash=sponsored_research_campaign_hash($config);
    $rev=(int)$campaign['current_revision'];
    $pdo->prepare('INSERT INTO sponsored_research_campaign_versions(public_id,campaign_id,revision_number,config_json,config_hash,change_reason,edited_by_user_id) VALUES(?,?,?,?,?,?,?)')
      ->execute([ulid_like(),(int)$campaign['id'],$rev,json_encode($config,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$hash,mb_substr(trim($reason),0,1000)?:null,(int)$viewer['id']]);
    $pdo->prepare('UPDATE sponsored_research_campaigns SET config_hash=? WHERE id=?')->execute([$hash,(int)$campaign['id']]);
    $campaign['config_hash']=$hash;return $campaign;
}
function sponsored_research_campaign_create(PDO $pdo,array $viewer,array $input): array {
    if(!sponsored_research_campaigns_ready($pdo))throw new RuntimeException('Sponsored Research campaigns require the latest database upgrade.');
    $profile=sponsor_account_require_approved($pdo,$viewer);
    $account=sponsored_research_campaign_account($pdo,$viewer,(string)($input['account_id']??''));
    $agent=sponsored_research_campaign_agent($pdo,$viewer,$input['research_agent_id']??null);
    $title=mb_substr(trim((string)($input['title']??'')),0,255);$brief=trim((string)($input['brief']??''));$objective=trim((string)($input['objective']??''));
    if($title===''||$brief===''||$objective==='')throw new InvalidArgumentException('Campaign title, brief, and objective are required.');
    $mode=(string)($input['access_mode']??'private');if(!isset(sponsored_research_campaign_access_modes()[$mode]))$mode='private';
    $currency=strtoupper(trim((string)($input['budget_currency']??'USD')));if(!preg_match('/^[A-Z]{3}$/',$currency))throw new InvalidArgumentException('Budget currency must be a three-letter ISO code.');
    $budget=array_key_exists('budget_cents',$input)?max(0,(int)$input['budget_cents']):sponsored_research_campaign_money_cents($input['budget']??'0');$max=(int)($input['max_participants']??0);$max=$max>0?$max:null;
    $starts=sponsored_research_campaign_datetime($input['starts_at']??'','Campaign start');$deadline=sponsored_research_campaign_datetime($input['submission_deadline']??'','Submission deadline');$reviewDeadline=sponsored_research_campaign_datetime($input['review_deadline']??'','Review deadline');
    if($starts&&$deadline&&strtotime($deadline)<=strtotime($starts))throw new InvalidArgumentException('Submission deadline must be after the campaign start.');
    if($deadline&&$reviewDeadline&&strtotime($reviewDeadline)<strtotime($deadline))throw new InvalidArgumentException('Review deadline cannot be before the submission deadline.');
    $eligibility=sponsored_research_campaign_eligibility($input['eligibility']??[]);$disclosures=sponsored_research_campaign_disclosures($input['disclosures']??[]);
    $questions=sponsored_research_campaign_questions_clean($input['questions']??[]);if(!$questions)throw new InvalidArgumentException('Add at least one campaign research question.');
    $public=ulid_like();$pdo->beginTransaction();
    try{
      $pdo->prepare("INSERT INTO sponsored_research_campaigns(public_id,sponsor_user_id,sponsor_profile_id,account_id,research_agent_id,title,brief,objective,status,access_mode,budget_currency,budget_cents,max_participants,eligibility_json,disclosure_json,starts_at,submission_deadline,review_deadline,current_revision,config_hash)
        VALUES(?,?,?,?,?,?,?,?,'draft',?,?,?,?,?,?,?,?,?,1,REPEAT('0',64))")
        ->execute([$public,(int)$viewer['id'],(int)$profile['id'],(int)$account['id'],$agent['id']??null,$title,$brief,$objective,$mode,$currency,$budget,$max,json_encode($eligibility,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),json_encode($disclosures,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$starts,$deadline,$reviewDeadline]);
      $id=(int)$pdo->lastInsertId();foreach($questions as $i=>$question)$pdo->prepare('INSERT INTO sponsored_research_campaign_questions(public_id,campaign_id,question,position) VALUES(?,?,?,?)')->execute([ulid_like(),$id,$question,$i]);
      $campaign=sponsored_research_campaign_by_public($pdo,$public);if(!$campaign)throw new RuntimeException('Campaign create failed.');
      $campaign=sponsored_research_campaign_snapshot($pdo,$campaign,$viewer,'Initial sponsored research campaign brief.');
      sponsored_research_campaign_event($pdo,$id,(int)$viewer['id'],'campaign_created',['revision'=>1,'account_public_id'=>$account['public_id'],'research_agent_public_id'=>$agent['public_id']??null]);
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return sponsored_research_campaign_by_public($pdo,$public)??[];
}
function sponsored_research_campaign_update(PDO $pdo,array $viewer,string $publicId,array $input): array {
    return sponsored_research_campaign_locked($pdo,$viewer,$publicId,function(array $campaign) use($pdo,$viewer,$publicId,$input){
        if(in_array((string)$campaign['status'],['completed','cancelled','archived'],true))throw new RuntimeException('Closed campaign configuration is immutable.');
        $agent=sponsored_research_campaign_agent($pdo,$viewer,$input['research_agent_id']??($campaign['research_agent_public_id']??null));
        $title=mb_substr(trim((string)($input['title']??$campaign['title'])),0,255);$brief=trim((string)($input['brief']??$campaign['brief']));$objective=trim((string)($input['objective']??$campaign['objective']));
        if($title===''||$brief===''||$objective==='')throw new InvalidArgumentException('Campaign title, brief, and objective are required.');
        $mode=(string)($input['access_mode']??$campaign['access_mode']);if(!isset(sponsored_research_campaign_access_modes()[$mode]))$mode=(string)$campaign['access_mode'];
        $currency=strtoupper(trim((string)($input['budget_currency']??$campaign['budget_currency'])));if(!preg_match('/^[A-Z]{3}$/',$currency))throw new InvalidArgumentException('Budget currency must be a three-letter ISO code.');
        $budget=array_key_exists('budget_cents',$input)?max(0,(int)$input['budget_cents']):(array_key_exists('budget',$input)?sponsored_research_campaign_money_cents($input['budget']):(int)$campaign['budget_cents']);$max=(int)($input['max_participants']??($campaign['max_participants']??0));$max=$max>0?$max:null;
        $starts=sponsored_research_campaign_datetime($input['starts_at']??($campaign['starts_at']??''),'Campaign start');$deadline=sponsored_research_campaign_datetime($input['submission_deadline']??($campaign['submission_deadline']??''),'Submission deadline');$reviewDeadline=sponsored_research_campaign_datetime($input['review_deadline']??($campaign['review_deadline']??''),'Review deadline');
        if($starts&&$deadline&&strtotime($deadline)<=strtotime($starts))throw new InvalidArgumentException('Submission deadline must be after the campaign start.');
        if($deadline&&$reviewDeadline&&strtotime($reviewDeadline)<strtotime($deadline))throw new InvalidArgumentException('Review deadline cannot be before the submission deadline.');
        $eligibility=array_key_exists('eligibility',$input)?sponsored_research_campaign_eligibility($input['eligibility']):sponsored_research_campaign_eligibility($campaign['eligibility']);
        $disclosures=array_key_exists('disclosures',$input)?sponsored_research_campaign_disclosures($input['disclosures']):sponsored_research_campaign_disclosures($campaign['disclosures']);
        $questions=array_key_exists('questions',$input)?sponsored_research_campaign_questions_clean($input['questions']):array_map(fn($q)=>(string)$q['question'],$campaign['questions']);if(!$questions)throw new InvalidArgumentException('Add at least one campaign research question.');
        $pdo->beginTransaction();try{
          $next=(int)$campaign['current_revision']+1;
          $pdo->prepare('UPDATE sponsored_research_campaigns SET research_agent_id=?,title=?,brief=?,objective=?,access_mode=?,budget_currency=?,budget_cents=?,max_participants=?,eligibility_json=?,disclosure_json=?,starts_at=?,submission_deadline=?,review_deadline=?,current_revision=?,updated_at=NOW() WHERE id=?')
            ->execute([$agent['id']??null,$title,$brief,$objective,$mode,$currency,$budget,$max,json_encode($eligibility,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),json_encode($disclosures,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$starts,$deadline,$reviewDeadline,$next,(int)$campaign['id']]);
          $pdo->prepare('DELETE FROM sponsored_research_campaign_questions WHERE campaign_id=?')->execute([(int)$campaign['id']]);foreach($questions as $i=>$question)$pdo->prepare('INSERT INTO sponsored_research_campaign_questions(public_id,campaign_id,question,position) VALUES(?,?,?,?)')->execute([ulid_like(),(int)$campaign['id'],$question,$i]);
          $fresh=sponsored_research_campaign_by_public($pdo,$publicId);if(!$fresh)throw new RuntimeException('Campaign update could not be reloaded.');$fresh=sponsored_research_campaign_snapshot($pdo,$fresh,$viewer,(string)($input['reason']??'Campaign brief updated.'));
          sponsored_research_campaign_event($pdo,(int)$campaign['id'],(int)$viewer['id'],'campaign_updated',['revision'=>$next,'config_hash'=>$fresh['config_hash']]);
          $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return sponsored_research_campaign_by_public($pdo,$publicId)??[];
    });
}
function sponsored_research_campaign_set_status(PDO $pdo,array $viewer,string $publicId,string $status): array {
    return sponsored_research_campaign_locked($pdo,$viewer,$publicId,function(array $campaign) use($pdo,$viewer,$publicId,$status){
        if(!isset(sponsored_research_campaign_statuses()[$status]))throw new InvalidArgumentException('Invalid campaign status.');
        $current=(string)$campaign['status'];if($current===$status)return $campaign;
        $allowed=['draft'=>['funding_required','scheduled','open','cancelled','archived'],'funding_required'=>['draft','scheduled','cancelled'],'scheduled'=>['open','paused','cancelled'],'open'=>['paused','closed','cancelled'],'paused'=>['open','closed','cancelled'],'closed'=>['under_review','completed','archived'],'under_review'=>['completed','closed'],'completed'=>['archived'],'cancelled'=>['archived'],'archived'=>[]];
        if(!in_array($status,$allowed[$current]??[],true))throw new InvalidArgumentException('That campaign status transition is not allowed.');
        $pdo->beginTransaction();try{
            $next=(int)$campaign['current_revision']+1;$pdo->prepare('UPDATE sponsored_research_campaigns SET status=?,current_revision=?,updated_at=NOW() WHERE id=?')->execute([$status,$next,(int)$campaign['id']]);
            $fresh=sponsored_research_campaign_by_public($pdo,$publicId);if(!$fresh)throw new RuntimeException('Campaign status could not be reloaded.');$fresh=sponsored_research_campaign_snapshot($pdo,$fresh,$viewer,'Campaign status changed from '.$current.' to '.$status.'.');
            sponsored_research_campaign_event($pdo,(int)$campaign['id'],(int)$viewer['id'],'campaign_status_changed',['from'=>$current,'to'=>$status,'revision'=>$next,'config_hash'=>$fresh['config_hash']]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return sponsored_research_campaign_by_public($pdo,$publicId)??[];
    });
}
function sponsored_research_campaign_list(PDO $pdo,array $viewer,int $limit=100): array {
    if(!sponsored_research_campaigns_ready($pdo))return [];$limit=max(1,min(300,$limit));$accounts=account_membership_accounts_for_user($pdo,$viewer,true);if(!$accounts)return [];
    $ids=array_map(fn($a)=>(int)$a['id'],$accounts);$marks=implode(',',array_fill(0,count($ids),'?'));
    $q=$pdo->prepare("SELECT c.*,a.public_id account_public_id,a.name account_name,ra.public_id research_agent_public_id,ra.name research_agent_name FROM sponsored_research_campaigns c JOIN accounts a ON a.id=c.account_id LEFT JOIN research_agents ra ON ra.id=c.research_agent_id WHERE c.account_id IN ($marks) ORDER BY FIELD(c.status,'open','scheduled','funding_required','draft','paused','closed','under_review','completed','cancelled','archived'),c.updated_at DESC LIMIT ".$limit);
    $q->execute($ids);return $q->fetchAll()?:[];
}
function sponsored_research_campaign_versions(PDO $pdo,array $viewer,string $publicId,int $limit=50): array {
    $campaign=sponsored_research_campaign_require_manage($pdo,$viewer,$publicId);$q=$pdo->prepare('SELECT v.*,u.username editor_username,u.display_name editor_display_name FROM sponsored_research_campaign_versions v JOIN users u ON u.id=v.edited_by_user_id WHERE v.campaign_id=? ORDER BY v.revision_number DESC LIMIT '.max(1,min(100,$limit)));$q->execute([(int)$campaign['id']]);return $q->fetchAll()?:[];
}

function sponsored_research_campaign_events(PDO $pdo,array $viewer,string $publicId,int $limit=100): array {
    $campaign=sponsored_research_campaign_require_manage($pdo,$viewer,$publicId);$limit=max(1,min(250,$limit));
    $q=$pdo->prepare("SELECT e.*,u.username actor_username,u.display_name actor_display_name FROM sponsored_research_campaign_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.campaign_id=? ORDER BY e.created_at DESC,e.id DESC LIMIT ".$limit);
    $q->execute([(int)$campaign['id']]);return $q->fetchAll()?:[];
}
