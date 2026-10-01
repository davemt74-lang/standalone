<?php
declare(strict_types=1);

function research_accounts_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_account_profiles')&&installer_table_exists($pdo,'sponsor_account_profiles')&&installer_table_exists($pdo,'research_account_governance_events');}
    catch(Throwable $e){return false;}
}
function research_account_json_list(mixed $value,int $limit=20): array {
    if(is_string($value))$value=preg_split('/[,\n]+/',$value)?:[];
    if(!is_array($value))return [];
    $out=[];foreach($value as $item){$item=trim((string)$item);if($item===''||in_array($item,$out,true))continue;$out[]=mb_substr($item,0,100);if(count($out)>=$limit)break;}return $out;
}
function research_account_decode(?string $json): array {
    if(!$json)return [];try{$v=json_decode($json,true,32,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(Throwable $e){return [];}
}
function research_account_profile(PDO $pdo,int $userId): ?array {
    if(!research_accounts_ready($pdo))return null;$q=$pdo->prepare('SELECT * FROM research_account_profiles WHERE user_id=? LIMIT 1');$q->execute([$userId]);$r=$q->fetch();if(!$r)return null;$r['specialties']=research_account_decode($r['specialties_json']??null);$r['languages']=research_account_decode($r['languages_json']??null);return $r;
}
function sponsor_account_profile(PDO $pdo,int $userId): ?array {
    if(!research_accounts_ready($pdo))return null;$q=$pdo->prepare('SELECT * FROM sponsor_account_profiles WHERE user_id=? LIMIT 1');$q->execute([$userId]);$r=$q->fetch();return $r?:null;
}
function research_account_event(PDO $pdo,string $authority,int $subjectUserId,int $actorUserId,string $eventType,?array $before,?array $after,string $reason=''): void {
    $allowedAuthorities=['research_account','sponsor_account'];$allowedEvents=['applied','profile_updated','approved','suspended','revoked','reopened'];
    if(!in_array($authority,$allowedAuthorities,true)||!in_array($eventType,$allowedEvents,true))throw new InvalidArgumentException('Unsupported governance event.');
    $pdo->prepare('INSERT INTO research_account_governance_events(public_id,authority_type,subject_user_id,actor_user_id,event_type,before_json,after_json,reason) VALUES(?,?,?,?,?,?,?,?)')
      ->execute([ulid_like(),$authority,$subjectUserId,$actorUserId,$eventType,$before?json_encode($before,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):null,$after?json_encode($after,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):null,$reason!==''?mb_substr($reason,0,500):null]);
}
function research_account_apply(PDO $pdo,array $user,array $input): array {
    if(!research_accounts_ready($pdo))throw new RuntimeException('Research Account governance requires the latest database upgrade.');
    if(($user['status']??'')!=='active')throw new RuntimeException('Only active users can apply for a Research Account.');
    $existing=research_account_profile($pdo,(int)$user['id']);$specialties=research_account_json_list($input['specialties']??[]);$languages=research_account_json_list($input['languages']??[]);$bio=mb_substr(trim((string)($input['biography']??'')),0,4000);
    if($existing&&in_array((string)$existing['status'],['approved','suspended'],true)){
        $before=$existing;$pdo->prepare('UPDATE research_account_profiles SET specialties_json=?,languages_json=?,biography=? WHERE user_id=?')->execute([json_encode($specialties),json_encode($languages),$bio!==''?$bio:null,(int)$user['id']]);
        $after=research_account_profile($pdo,(int)$user['id']);research_account_event($pdo,'research_account',(int)$user['id'],(int)$user['id'],'profile_updated',$before,$after,'Researcher updated profile.');return $after;
    }
    if($existing){
        $before=$existing;$pdo->prepare("UPDATE research_account_profiles SET status='pending',specialties_json=?,languages_json=?,biography=?,marketplace_visible=0,approved_at=NULL,approved_by_user_id=NULL,suspended_at=NULL,revoked_at=NULL,decision_reason=NULL,applied_at=NOW() WHERE user_id=?")->execute([json_encode($specialties),json_encode($languages),$bio!==''?$bio:null,(int)$user['id']]);
        $after=research_account_profile($pdo,(int)$user['id']);research_account_event($pdo,'research_account',(int)$user['id'],(int)$user['id'],'reopened',$before,$after,'Research Account application reopened.');return $after;
    }
    $pdo->prepare("INSERT INTO research_account_profiles(public_id,user_id,status,specialties_json,languages_json,biography) VALUES(?,?,'pending',?,?,?)")->execute([ulid_like(),(int)$user['id'],json_encode($specialties),json_encode($languages),$bio!==''?$bio:null]);
    $after=research_account_profile($pdo,(int)$user['id']);research_account_event($pdo,'research_account',(int)$user['id'],(int)$user['id'],'applied',null,$after,'Research Account application submitted.');return $after;
}
function sponsor_account_apply(PDO $pdo,array $user,array $input): array {
    if(!research_accounts_ready($pdo))throw new RuntimeException('Sponsor Account governance requires the latest database upgrade.');
    if(($user['status']??'')!=='active')throw new RuntimeException('Only active users can apply for a Sponsor Account.');
    $existing=sponsor_account_profile($pdo,(int)$user['id']);$org=mb_substr(trim((string)($input['organization_name']??'')),0,190);$website=mb_substr(trim((string)($input['website_url']??'')),0,500);
    if($existing&&in_array((string)$existing['status'],['approved','suspended'],true)){
        $before=$existing;$pdo->prepare('UPDATE sponsor_account_profiles SET organization_name=?,website_url=? WHERE user_id=?')->execute([$org!==''?$org:null,$website!==''?$website:null,(int)$user['id']]);$after=sponsor_account_profile($pdo,(int)$user['id']);research_account_event($pdo,'sponsor_account',(int)$user['id'],(int)$user['id'],'profile_updated',$before,$after,'Sponsor updated profile.');return $after;
    }
    if($existing){$before=$existing;$pdo->prepare("UPDATE sponsor_account_profiles SET status='pending',organization_name=?,website_url=?,approved_at=NULL,approved_by_user_id=NULL,suspended_at=NULL,revoked_at=NULL,decision_reason=NULL,applied_at=NOW() WHERE user_id=?")->execute([$org!==''?$org:null,$website!==''?$website:null,(int)$user['id']]);$after=sponsor_account_profile($pdo,(int)$user['id']);research_account_event($pdo,'sponsor_account',(int)$user['id'],(int)$user['id'],'reopened',$before,$after,'Sponsor Account application reopened.');return $after;}
    $pdo->prepare("INSERT INTO sponsor_account_profiles(public_id,user_id,status,organization_name,website_url) VALUES(?,?,'pending',?,?)")->execute([ulid_like(),(int)$user['id'],$org!==''?$org:null,$website!==''?$website:null]);$after=sponsor_account_profile($pdo,(int)$user['id']);research_account_event($pdo,'sponsor_account',(int)$user['id'],(int)$user['id'],'applied',null,$after,'Sponsor Account application submitted.');return $after;
}
function research_account_admin_decide(PDO $pdo,array $admin,int $userId,string $authority,string $status,string $reason,string $verificationLevel='basic'): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required.');
    if(function_exists('admin_access_assert_capability'))admin_access_assert_capability($pdo,$admin,'admin.accounts.manage');
    $status=trim($status);if(!in_array($status,['approved','suspended','revoked'],true))throw new InvalidArgumentException('Unsupported approval state.');
    $reason=trim($reason);if($reason===''&&$status!=='approved')throw new InvalidArgumentException('A reason is required to suspend or revoke research authority.');
    $table=$authority==='research_account'?'research_account_profiles':($authority==='sponsor_account'?'sponsor_account_profiles':'');if($table==='')throw new InvalidArgumentException('Unknown research authority.');
    $profile=$authority==='research_account'?research_account_profile($pdo,$userId):sponsor_account_profile($pdo,$userId);if(!$profile)throw new RuntimeException('The user has not applied for this authority.');$before=$profile;
    if($authority==='research_account'){$allowed=['basic','identity','qualification','organization','payout'];}else{$allowed=['basic','identity','organization','billing'];}
    if(!in_array($verificationLevel,$allowed,true))$verificationLevel='basic';
    $sql="UPDATE {$table} SET status=?,verification_level=?,decision_reason=?,approved_by_user_id=?,approved_at=".($status==='approved'?'NOW()':'approved_at').",suspended_at=".($status==='suspended'?'NOW()':'NULL').",revoked_at=".($status==='revoked'?'NOW()':'NULL');
    if($authority==='research_account'&&$status!=='approved')$sql.=',marketplace_visible=0';
    $sql.=' WHERE user_id=?';$pdo->prepare($sql)->execute([$status,$verificationLevel,$reason!==''?mb_substr($reason,0,500):null,(int)$admin['id'],$userId]);
    $after=$authority==='research_account'?research_account_profile($pdo,$userId):sponsor_account_profile($pdo,$userId);research_account_event($pdo,$authority,$userId,(int)$admin['id'],$status,$before,$after,$reason);return $after;
}
function research_account_is_approved(PDO $pdo,array|int $user): bool {
    $uid=is_array($user)?(int)($user['id']??0):(int)$user;if($uid<1)return false;$profile=research_account_profile($pdo,$uid);if(!$profile||$profile['status']!=='approved')return false;$q=$pdo->prepare("SELECT status FROM users WHERE id=?");$q->execute([$uid]);return $q->fetchColumn()==='active';
}
function sponsor_account_is_approved(PDO $pdo,array|int $user): bool {
    $uid=is_array($user)?(int)($user['id']??0):(int)$user;if($uid<1)return false;$profile=sponsor_account_profile($pdo,$uid);if(!$profile||$profile['status']!=='approved')return false;$q=$pdo->prepare("SELECT status FROM users WHERE id=?");$q->execute([$uid]);return $q->fetchColumn()==='active';
}
function research_account_require_approved(PDO $pdo,array $user): array {
    $profile=research_account_profile($pdo,(int)$user['id']);if(!$profile||!research_account_is_approved($pdo,$user))throw new RuntimeException('An approved Research Account is required for paid or sponsored research.');return $profile;
}
function sponsor_account_require_approved(PDO $pdo,array $user): array {
    $profile=sponsor_account_profile($pdo,(int)$user['id']);if(!$profile||!sponsor_account_is_approved($pdo,$user))throw new RuntimeException('An approved Sponsor Account is required for sponsored campaign authority.');return $profile;
}
function research_account_eligibility_snapshot(PDO $pdo,array $user): array {
    $research=research_account_profile($pdo,(int)$user['id']);$sponsor=sponsor_account_profile($pdo,(int)$user['id']);
    return ['user_id'=>(int)$user['id'],'user_status'=>(string)($user['status']??''),'research_status'=>$research['status']??'not_applied','research_approved'=>$research?research_account_is_approved($pdo,$user):false,'verification_level'=>$research['verification_level']??null,'marketplace_visible'=>(bool)($research['marketplace_visible']??false),'payout_readiness'=>$research['payout_readiness']??null,'sponsor_status'=>$sponsor['status']??'not_applied','sponsor_approved'=>$sponsor?sponsor_account_is_approved($pdo,$user):false];
}
function research_account_governance_history(PDO $pdo,int $userId,int $limit=100): array {
    if(!research_accounts_ready($pdo))return [];$q=$pdo->prepare('SELECT e.*,u.display_name actor_name,u.username actor_username FROM research_account_governance_events e JOIN users u ON u.id=e.actor_user_id WHERE e.subject_user_id=? ORDER BY e.id DESC LIMIT '.max(1,min(250,$limit)));$q->execute([$userId]);return $q->fetchAll();
}
