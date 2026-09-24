<?php
declare(strict_types=1);

function account_membership_ready(PDO $pdo): bool {
    try{return account_admin_ready($pdo)&&installer_table_exists($pdo,'account_invitations')&&installer_table_exists($pdo,'account_membership_events');}
    catch(Throwable $e){return false;}
}
function account_membership_email(string $email): string {
    $email=strtolower(trim($email));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid email address.');return mb_substr($email,0,255);
}
function account_membership_reason(string $reason,string $fallback='Account membership updated.'): string {
    $reason=trim($reason);if($reason==='')$reason=$fallback;return mb_substr($reason,0,500);
}
function account_membership_request_hash(?string $value,string $scope): ?string {
    $value=trim((string)$value);return $value===''?null:hash('sha256','annotated-membership|'.$scope.'|'.$value);
}
function account_membership_event(PDO $pdo,int $accountId,?int $actorUserId,?int $subjectUserId,?int $invitationId,string $eventType,mixed $before,mixed $after,string $reason): void {
    if(!account_membership_ready($pdo))return;
    $enc=fn(mixed $v)=>$v===null?null:json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $ip=PHP_SAPI==='cli'?null:account_membership_request_hash($_SERVER['REMOTE_ADDR']??null,'ip');
    $ua=PHP_SAPI==='cli'?null:account_membership_request_hash($_SERVER['HTTP_USER_AGENT']??null,'ua');
    $pdo->prepare("INSERT INTO account_membership_events(public_id,account_id,actor_user_id,subject_user_id,invitation_id,event_type,before_json,after_json,reason,actor_ip_hash,actor_user_agent_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([ulid_like(),$accountId,$actorUserId,$subjectUserId,$invitationId,mb_substr($eventType,0,80),$enc($before),$enc($after),account_membership_reason($reason),$ip,$ua]);
}
function account_membership_events(PDO $pdo,int $accountId,int $limit=100): array {
    if(!account_membership_ready($pdo))return [];$limit=max(1,min(300,$limit));
    $q=$pdo->prepare("SELECT e.*,au.username actor_username,su.username subject_username,i.public_id invitation_public_id,i.invited_email
      FROM account_membership_events e LEFT JOIN users au ON au.id=e.actor_user_id LEFT JOIN users su ON su.id=e.subject_user_id LEFT JOIN account_invitations i ON i.id=e.invitation_id
      WHERE e.account_id=? ORDER BY e.created_at DESC,e.id DESC LIMIT ".$limit);$q->execute([$accountId]);return $q->fetchAll()?:[];
}
function account_membership_actor_role(PDO $pdo,array $actor,int $accountId): ?string {
    if(($actor['role']??'')==='admin')return 'site_admin';
    $q=$pdo->prepare('SELECT account_role FROM account_members WHERE account_id=? AND user_id=? LIMIT 1');$q->execute([$accountId,(int)$actor['id']]);$role=$q->fetchColumn();return $role===false?null:(string)$role;
}
function account_membership_require_access(PDO $pdo,array $actor,array $account): string {
    $role=account_membership_actor_role($pdo,$actor,(int)$account['id']);if($role===null)throw new RuntimeException('You do not have access to this commercial account.');return $role;
}
function account_membership_require_manage(PDO $pdo,array $actor,array $account): string {
    $role=account_membership_require_access($pdo,$actor,$account);if(!in_array($role,['site_admin','owner','admin'],true))throw new RuntimeException('Account owner or account admin access is required.');return $role;
}
function account_membership_require_owner(PDO $pdo,array $actor,array $account): string {
    $role=account_membership_require_access($pdo,$actor,$account);if(!in_array($role,['site_admin','owner'],true))throw new RuntimeException('Account owner access is required.');return $role;
}
function account_membership_accounts_for_user(PDO $pdo,array $user,bool $manageableOnly=false): array {
    if(!subscriptions_ready($pdo))return [];
    if(($user['role']??'')==='admin'&&$manageableOnly){
        return $pdo->query("SELECT a.*, 'site_admin' account_role,p.public_id package_public_id,p.slug package_slug,p.name package_name,p.monthly_price_cents,p.monthly_ai_token_allowance,p.member_limit,p.feature_json
          FROM accounts a JOIN subscription_packages p ON p.id=a.package_id WHERE a.status<>'closed' ORDER BY a.name,a.id")->fetchAll()?:[];
    }
    $sql="SELECT a.*,am.account_role,p.public_id package_public_id,p.slug package_slug,p.name package_name,p.monthly_price_cents,p.monthly_ai_token_allowance,p.member_limit,p.feature_json
      FROM account_members am JOIN accounts a ON a.id=am.account_id JOIN subscription_packages p ON p.id=a.package_id WHERE am.user_id=? AND a.status<>'closed'";
    if($manageableOnly)$sql.=" AND am.account_role IN ('owner','admin')";
    $sql.=" ORDER BY (a.personal_user_id=?) DESC,a.name,a.id";$q=$pdo->prepare($sql);$q->execute([(int)$user['id'],(int)$user['id']]);return $q->fetchAll()?:[];
}
function account_membership_account_for_user(PDO $pdo,array $user,?string $accountPublicId=null,bool $manage=false): array {
    $rows=account_membership_accounts_for_user($pdo,$user,$manage);if(!$rows)throw new RuntimeException($manage?'You do not administer a commercial account.':'You do not belong to a commercial account.');
    if($accountPublicId===null||trim($accountPublicId)==='')return $rows[0];
    foreach($rows as $row)if(hash_equals((string)$row['public_id'],trim($accountPublicId)))return $row;
    throw new RuntimeException($manage?'You do not administer that commercial account.':'You do not belong to that commercial account.');
}
function account_membership_expire_pending(PDO $pdo,int $accountId): int {
    if(!account_membership_ready($pdo))return 0;$q=$pdo->prepare("UPDATE account_invitations SET status='expired' WHERE account_id=? AND status='pending' AND expires_at<=NOW()");$q->execute([$accountId]);return $q->rowCount();
}
function account_membership_pending_reserved_count(PDO $pdo,int $accountId,?int $excludeInvitationId=null): int {
    if(!account_membership_ready($pdo))return 0;account_membership_expire_pending($pdo,$accountId);
    $sql="SELECT COUNT(*) FROM account_invitations WHERE account_id=? AND status='pending' AND reserves_seat=1 AND expires_at>NOW()";$args=[$accountId];
    if($excludeInvitationId!==null){$sql.=' AND id<>?';$args[]=$excludeInvitationId;}$q=$pdo->prepare($sql);$q->execute($args);return (int)$q->fetchColumn();
}
function account_membership_seat_summary(PDO $pdo,int $accountId): array {
    $account=account_admin_get($pdo,$accountId);if(!$account)throw new RuntimeException('Account not found.');
    $q=$pdo->prepare('SELECT COUNT(*) FROM account_members WHERE account_id=?');$q->execute([$accountId]);$members=(int)$q->fetchColumn();
    $pending=account_membership_pending_reserved_count($pdo,$accountId);$limit=account_admin_effective_member_limit($pdo,$accountId);$used=$members+$pending;
    return ['member_count'=>$members,'pending_reserved'=>$pending,'used_seats'=>$used,'member_limit'=>$limit,'available_seats'=>max(0,$limit-$used),'over_capacity'=>$members>$limit,'over_reserved'=>$used>$limit,'full'=>$used>=$limit];
}
function account_membership_invitations(PDO $pdo,int $accountId,bool $pendingOnly=false,int $limit=200): array {
    if(!account_membership_ready($pdo))return [];account_membership_expire_pending($pdo,$accountId);$limit=max(1,min(500,$limit));
    $sql="SELECT i.*,iu.username invited_by_username,au.username accepted_by_username,ru.username revoked_by_username FROM account_invitations i
      LEFT JOIN users iu ON iu.id=i.invited_by_user_id LEFT JOIN users au ON au.id=i.accepted_by_user_id LEFT JOIN users ru ON ru.id=i.revoked_by_user_id WHERE i.account_id=?";
    if($pendingOnly)$sql.=" AND i.status='pending' AND i.expires_at>NOW()";$sql.=" ORDER BY i.created_at DESC,i.id DESC LIMIT ".$limit;$q=$pdo->prepare($sql);$q->execute([$accountId]);return $q->fetchAll()?:[];
}
function account_membership_invitation_by_public(PDO $pdo,string $publicId): ?array {
    if(!account_membership_ready($pdo))return null;$q=$pdo->prepare("SELECT i.*,a.public_id account_public_id,a.name account_name,a.status account_status,p.name package_name FROM account_invitations i JOIN accounts a ON a.id=i.account_id JOIN subscription_packages p ON p.id=a.package_id WHERE i.public_id=? LIMIT 1");$q->execute([trim($publicId)]);return $q->fetch()?:null;
}
function account_membership_invitation_by_token(PDO $pdo,string $token): ?array {
    if(!account_membership_ready($pdo))return null;$token=trim($token);if(!preg_match('/^[a-f0-9]{64}$/',$token))return null;$hash=hash('sha256',$token);
    $q=$pdo->prepare("SELECT i.*,a.public_id account_public_id,a.name account_name,a.status account_status,p.name package_name FROM account_invitations i JOIN accounts a ON a.id=i.account_id JOIN subscription_packages p ON p.id=a.package_id WHERE i.token_hash=? LIMIT 1");$q->execute([$hash]);$row=$q->fetch();if(!$row)return null;
    if($row['status']==='pending'&&strtotime((string)$row['expires_at'])<=time()){$pdo->prepare("UPDATE account_invitations SET status='expired' WHERE id=? AND status='pending'")->execute([(int)$row['id']]);$row['status']='expired';}
    return $row;
}
function account_membership_invite_url(array $config,string $token): string {
    $base=rtrim((string)($config['app']['base_url']??''),'/');$parts=parse_url($base);if(!$parts||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host']))throw new RuntimeException('Invitation links require an HTTPS app.base_url.');
    return $base.'/account-invite.php?token='.rawurlencode($token);
}
function account_membership_mail_settings(array $config): array {
    $m=is_array($config['mail']??null)?$config['mail']:[];
    return ['enabled'=>!empty($m['enabled']),'from_email'=>trim((string)($m['from_email']??'')),'from_name'=>trim((string)($m['from_name']??($config['app']['name']??'Annotated')))];
}
function account_membership_deliver_invitation(PDO $pdo,array $config,array $invite,string $token): array {
    $url=account_membership_invite_url($config,$token);$mail=account_membership_mail_settings($config);$status='link_only';$error=null;$sent=false;
    if($mail['enabled']){
        if(!filter_var($mail['from_email'],FILTER_VALIDATE_EMAIL)){$status='failed';$error='Configured invitation from_email is invalid.';}
        else{
            $fromName=str_replace(["\r","\n"],' ',mb_substr($mail['from_name']?:'Annotated',0,120));$to=account_membership_email((string)$invite['invited_email']);
            $subject='You are invited to '.(string)$invite['account_name'].' on Annotated';
            $body="You have been invited to join ".(string)$invite['account_name']." as ".str_replace('_',' ',(string)$invite['account_role']).".\n\nAccept invitation:\n".$url."\n\nThis link expires ".(string)$invite['expires_at']." UTC.\n";
            $headers=['Content-Type: text/plain; charset=UTF-8','From: '.$fromName.' <'.$mail['from_email'].'>'];
            $sent=@mail($to,$subject,$body,implode("\r\n",$headers));$status=$sent?'sent':'failed';if(!$sent)$error='PHP mail transport did not accept the invitation message.';
        }
    }
    $pdo->prepare('UPDATE account_invitations SET delivery_status=?,send_count=send_count+?,last_sent_at=?,last_delivery_error=? WHERE id=?')
      ->execute([$status,$mail['enabled']?1:0,$mail['enabled']?gmdate('Y-m-d H:i:s'):null,$error,(int)$invite['id']]);
    $invite['delivery_status']=$status;$invite['last_delivery_error']=$error;$invite['invite_url']=$url;return $invite;
}
function account_membership_create_invitation(PDO $pdo,array $config,array $actor,string $accountPublicId,string $email,string $role='member',bool $reserveSeat=true,int $expiresDays=7,string $reason='Invite account member.'): array {
    if(!account_membership_ready($pdo))throw new RuntimeException('Account invitations require the latest database upgrade.');
    $seed=account_admin_get($pdo,$accountPublicId);if(!$seed)throw new RuntimeException('Account not found.');account_membership_require_manage($pdo,$actor,$seed);$email=account_membership_email($email);$newRole=in_array($role,['admin','member'],true)?$role:'member';$reason=account_membership_reason($reason,'Invite account member.');$expiresDays=max(1,min(30,$expiresDays));
    return commercial_account_with_lock($pdo,(int)$seed['id'],function()use($pdo,$config,$actor,$seed,$email,$newRole,$reserveSeat,$expiresDays,$reason){
        $account=account_admin_get($pdo,(int)$seed['id']);if(!$account)throw new RuntimeException('Account not found.');if($account['status']!=='active')throw new RuntimeException('Only active accounts can send invitations.');
        $q=$pdo->prepare("SELECT am.user_id FROM account_members am JOIN users u ON u.id=am.user_id WHERE am.account_id=? AND LOWER(u.email)=? LIMIT 1");$q->execute([(int)$account['id'],$email]);if($q->fetchColumn())throw new RuntimeException('That email already belongs to an account member.');
        account_membership_expire_pending($pdo,(int)$account['id']);$q=$pdo->prepare("SELECT id FROM account_invitations WHERE account_id=? AND invited_email=? AND status='pending' AND expires_at>NOW() LIMIT 1");$q->execute([(int)$account['id'],$email]);if($q->fetchColumn())throw new RuntimeException('A pending invitation already exists for that email.');
        $seat=account_membership_seat_summary($pdo,(int)$account['id']);if($reserveSeat&&$seat['used_seats']>=$seat['member_limit'])throw new RuntimeException('The account has no available seats for another invitation.');
        $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$expires=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+'.$expiresDays.' days')->format('Y-m-d H:i:s');$public=ulid_like();
        $pdo->beginTransaction();try{
            $pdo->prepare("INSERT INTO account_invitations(public_id,account_id,invited_email,account_role,token_hash,reserves_seat,expires_at,invited_by_user_id,reason) VALUES(?,?,?,?,?,?,?,?,?)")
              ->execute([$public,(int)$account['id'],$email,$newRole,$hash,$reserveSeat?1:0,$expires,(int)$actor['id'],$reason]);$id=(int)$pdo->lastInsertId();
            account_membership_event($pdo,(int)$account['id'],(int)$actor['id'],null,$id,'invitation_created',null,['email'=>$email,'account_role'=>$newRole,'reserves_seat'=>$reserveSeat,'expires_at'=>$expires],$reason);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        $invite=account_membership_invitation_by_public($pdo,$public)??throw new RuntimeException('Created invitation could not be loaded.');
        return account_membership_deliver_invitation($pdo,$config,$invite,$token);
    });
}
function account_membership_resend_invitation(PDO $pdo,array $config,array $actor,string $invitationPublicId,int $expiresDays=7,string $reason='Resend account invitation.'): array {
    $invite=account_membership_invitation_by_public($pdo,$invitationPublicId);if(!$invite)throw new RuntimeException('Invitation not found.');$account=account_admin_get($pdo,(int)$invite['account_id'])??throw new RuntimeException('Account not found.');account_membership_require_manage($pdo,$actor,$account);$reason=account_membership_reason($reason,'Resend account invitation.');$expiresDays=max(1,min(30,$expiresDays));
    return commercial_account_with_lock($pdo,(int)$account['id'],function()use($pdo,$config,$actor,$invite,$account,$expiresDays,$reason){
        $fresh=account_membership_invitation_by_public($pdo,(string)$invite['public_id']);if(!$fresh||$fresh['status']!=='pending')throw new RuntimeException('Only pending invitations can be resent.');if($account['status']!=='active')throw new RuntimeException('Only active accounts can send invitations.');
        $token=bin2hex(random_bytes(32));$expires=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+'.$expiresDays.' days')->format('Y-m-d H:i:s');
        $pdo->prepare("UPDATE account_invitations SET token_hash=?,expires_at=?,delivery_status='link_only',last_delivery_error=NULL WHERE id=?")->execute([hash('sha256',$token),$expires,(int)$fresh['id']]);
        account_membership_event($pdo,(int)$account['id'],(int)$actor['id'],null,(int)$fresh['id'],'invitation_resent',['expires_at'=>$fresh['expires_at']],['expires_at'=>$expires],$reason);
        $fresh=account_membership_invitation_by_public($pdo,(string)$fresh['public_id'])??$fresh;return account_membership_deliver_invitation($pdo,$config,$fresh,$token);
    });
}
function account_membership_revoke_invitation(PDO $pdo,array $actor,string $invitationPublicId,string $reason='Revoke account invitation.'): array {
    $invite=account_membership_invitation_by_public($pdo,$invitationPublicId);if(!$invite)throw new RuntimeException('Invitation not found.');$account=account_admin_get($pdo,(int)$invite['account_id'])??throw new RuntimeException('Account not found.');account_membership_require_manage($pdo,$actor,$account);$reason=account_membership_reason($reason,'Revoke account invitation.');
    return commercial_account_with_lock($pdo,(int)$account['id'],function()use($pdo,$actor,$invite,$account,$reason){
        $fresh=account_membership_invitation_by_public($pdo,(string)$invite['public_id']);if(!$fresh||$fresh['status']!=='pending')throw new RuntimeException('Only pending invitations can be revoked.');
        $pdo->prepare("UPDATE account_invitations SET status='revoked',revoked_by_user_id=?,revoked_at=NOW() WHERE id=?")->execute([(int)$actor['id'],(int)$fresh['id']]);
        account_membership_event($pdo,(int)$account['id'],(int)$actor['id'],null,(int)$fresh['id'],'invitation_revoked',['status'=>'pending'],['status'=>'revoked'],$reason);
        return account_membership_invitation_by_public($pdo,(string)$fresh['public_id'])??$fresh;
    });
}
function account_membership_remember_invite_token(PDO $pdo,string $token): ?array {
    $invite=account_membership_invitation_by_token($pdo,$token);if(!$invite||$invite['status']!=='pending')return null;
    if(PHP_SAPI!=='cli'){$_SESSION['pending_account_invite_token']=$token;$_SESSION['after_login']='/account-invite.php';}
    return $invite;
}
function account_membership_pending_session_invite(PDO $pdo): ?array {
    if(PHP_SAPI==='cli')return null;$token=(string)($_SESSION['pending_account_invite_token']??'');return $token!==''?account_membership_invitation_by_token($pdo,$token):null;
}
function account_membership_clear_pending_session(): void {
    if(PHP_SAPI!=='cli')unset($_SESSION['pending_account_invite_token']);
}
function account_membership_accept_invitation(PDO $pdo,array $user,string $token): array {
    $invite=account_membership_invitation_by_token($pdo,$token);if(!$invite||$invite['status']!=='pending')throw new RuntimeException('This invitation is invalid, expired, or no longer available.');
    if(strtolower((string)$user['email'])!==strtolower((string)$invite['invited_email']))throw new RuntimeException('Sign in with the email address this invitation was sent to.');
    return commercial_account_with_lock($pdo,(int)$invite['account_id'],function()use($pdo,$user,$invite){
        $fresh=account_membership_invitation_by_public($pdo,(string)$invite['public_id']);if(!$fresh||$fresh['status']!=='pending'||strtotime((string)$fresh['expires_at'])<=time())throw new RuntimeException('This invitation is invalid, expired, or no longer available.');
        $account=account_admin_get($pdo,(int)$fresh['account_id'])??throw new RuntimeException('Account not found.');if($account['status']!=='active')throw new RuntimeException('This account is not currently accepting members.');
        $q=$pdo->prepare('SELECT account_role FROM account_members WHERE account_id=? AND user_id=?');$q->execute([(int)$account['id'],(int)$user['id']]);$existing=$q->fetchColumn();
        if($existing===false){
            $q=$pdo->prepare('SELECT COUNT(*) FROM account_members WHERE account_id=?');$q->execute([(int)$account['id']]);$members=(int)$q->fetchColumn();$otherReserved=account_membership_pending_reserved_count($pdo,(int)$account['id'],(int)$fresh['id']);$limit=account_admin_effective_member_limit($pdo,(int)$account['id']);
            if($members+$otherReserved+1>$limit)throw new RuntimeException('This account no longer has an available seat for the invitation.');
        }
        $pdo->beginTransaction();try{
            if($existing===false)$pdo->prepare('INSERT INTO account_members(account_id,user_id,account_role) VALUES(?,?,?)')->execute([(int)$account['id'],(int)$user['id'],(string)$fresh['account_role']]);
            $pdo->prepare("UPDATE account_invitations SET status='accepted',accepted_by_user_id=?,accepted_at=NOW() WHERE id=? AND status='pending'")->execute([(int)$user['id'],(int)$fresh['id']]);
            account_membership_event($pdo,(int)$account['id'],(int)$user['id'],(int)$user['id'],(int)$fresh['id'],'invitation_accepted',['status'=>'pending'],['status'=>'accepted','account_role'=>$existing===false?(string)$fresh['account_role']:(string)$existing],$existing===false?'Account invitation accepted.':'Invitation accepted by an existing account member.');
            if(function_exists('account_admin_event'))account_admin_event($pdo,(int)$account['id'],(int)$user['id'],(int)$user['id'],'member_joined_via_invite',null,['account_role'=>$existing===false?(string)$fresh['account_role']:(string)$existing],'Account invitation accepted.');
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        account_membership_clear_pending_session();return ['account'=>account_admin_get($pdo,(int)$account['id']),'role'=>$existing===false?(string)$fresh['account_role']:(string)$existing,'joined'=>$existing===false];
    });
}
function account_membership_decline_invitation(PDO $pdo,array $user,string $token): array {
    $invite=account_membership_invitation_by_token($pdo,$token);if(!$invite||$invite['status']!=='pending')throw new RuntimeException('This invitation is invalid, expired, or no longer available.');
    if(strtolower((string)$user['email'])!==strtolower((string)$invite['invited_email']))throw new RuntimeException('Sign in with the email address this invitation was sent to.');
    return commercial_account_with_lock($pdo,(int)$invite['account_id'],function()use($pdo,$user,$invite){
        $fresh=account_membership_invitation_by_public($pdo,(string)$invite['public_id']);if(!$fresh||$fresh['status']!=='pending')throw new RuntimeException('This invitation is no longer available.');
        $pdo->prepare("UPDATE account_invitations SET status='declined' WHERE id=?")->execute([(int)$fresh['id']]);account_membership_event($pdo,(int)$fresh['account_id'],(int)$user['id'],(int)$user['id'],(int)$fresh['id'],'invitation_declined',['status'=>'pending'],['status'=>'declined'],'Invitation declined.');
        account_membership_clear_pending_session();return account_membership_invitation_by_public($pdo,(string)$fresh['public_id'])??$fresh;
    });
}
function account_membership_update_role(PDO $pdo,array $actor,string $accountPublicId,int $userId,string $role,string $reason='Update account member role.'): array {
    $account=account_admin_get($pdo,$accountPublicId)??throw new RuntimeException('Account not found.');account_membership_require_manage($pdo,$actor,$account);$newRole=in_array($role,['admin','member'],true)?$role:'member';$reason=account_membership_reason($reason,'Update account member role.');
    return commercial_account_with_lock($pdo,(int)$account['id'],function()use($pdo,$actor,$account,$userId,$newRole,$reason){
        $q=$pdo->prepare('SELECT account_role FROM account_members WHERE account_id=? AND user_id=?');$q->execute([(int)$account['id'],$userId]);$old=$q->fetchColumn();if($old===false)throw new RuntimeException('Account member not found.');if($old==='owner')throw new RuntimeException('Transfer ownership before changing the owner role.');if($old===$newRole)return account_admin_members($pdo,(int)$account['id']);
        $pdo->prepare('UPDATE account_members SET account_role=? WHERE account_id=? AND user_id=?')->execute([$newRole,(int)$account['id'],$userId]);account_membership_event($pdo,(int)$account['id'],(int)$actor['id'],$userId,null,'member_role_changed',['account_role'=>$old],['account_role'=>$newRole],$reason);if(function_exists('account_admin_event'))account_admin_event($pdo,(int)$account['id'],(int)$actor['id'],$userId,'member_role_changed',['account_role'=>$old],['account_role'=>$newRole],$reason);return account_admin_members($pdo,(int)$account['id']);
    });
}
function account_membership_remove_member(PDO $pdo,array $actor,string $accountPublicId,int $userId,string $reason='Remove account member.'): array {
    $account=account_admin_get($pdo,$accountPublicId)??throw new RuntimeException('Account not found.');account_membership_require_manage($pdo,$actor,$account);$reason=account_membership_reason($reason,'Remove account member.');
    return commercial_account_with_lock($pdo,(int)$account['id'],function()use($pdo,$actor,$account,$userId,$reason){
        $q=$pdo->prepare('SELECT account_role FROM account_members WHERE account_id=? AND user_id=?');$q->execute([(int)$account['id'],$userId]);$role=$q->fetchColumn();if($role===false)throw new RuntimeException('Account member not found.');if($role==='owner'||(int)$account['owner_user_id']===$userId)throw new RuntimeException('The account owner cannot be removed.');
        $pdo->prepare('DELETE FROM account_members WHERE account_id=? AND user_id=?')->execute([(int)$account['id'],$userId]);account_membership_event($pdo,(int)$account['id'],(int)$actor['id'],$userId,null,'member_removed',['account_role'=>$role],null,$reason);if(function_exists('account_admin_event'))account_admin_event($pdo,(int)$account['id'],(int)$actor['id'],$userId,'member_removed',['account_role'=>$role],null,$reason);return account_admin_members($pdo,(int)$account['id']);
    });
}
function account_membership_transfer_owner(PDO $pdo,array $actor,string $accountPublicId,int $newOwnerUserId,string $reason='Transfer account ownership.'): array {
    $account=account_admin_get($pdo,$accountPublicId)??throw new RuntimeException('Account not found.');account_membership_require_owner($pdo,$actor,$account);if($account['account_type']==='personal')throw new RuntimeException('Personal account ownership cannot be transferred.');$reason=account_membership_reason($reason,'Transfer account ownership.');
    return commercial_account_with_lock($pdo,(int)$account['id'],function()use($pdo,$actor,$account,$newOwnerUserId,$reason){
        $fresh=account_admin_get($pdo,(int)$account['id'])??throw new RuntimeException('Account not found.');$oldOwner=(int)$fresh['owner_user_id'];if($oldOwner===$newOwnerUserId)return $fresh;
        $q=$pdo->prepare('SELECT account_role FROM account_members WHERE account_id=? AND user_id=?');$q->execute([(int)$fresh['id'],$newOwnerUserId]);$targetRole=$q->fetchColumn();if($targetRole===false)throw new RuntimeException('The new owner must already be an account member.');
        $pdo->beginTransaction();try{
            $pdo->prepare("UPDATE account_members SET account_role='admin' WHERE account_id=? AND user_id=?")->execute([(int)$fresh['id'],$oldOwner]);
            $pdo->prepare("UPDATE account_members SET account_role='owner' WHERE account_id=? AND user_id=?")->execute([(int)$fresh['id'],$newOwnerUserId]);
            $pdo->prepare('UPDATE accounts SET owner_user_id=? WHERE id=?')->execute([$newOwnerUserId,(int)$fresh['id']]);
            account_membership_event($pdo,(int)$fresh['id'],(int)$actor['id'],$newOwnerUserId,null,'ownership_transferred',['owner_user_id'=>$oldOwner],['owner_user_id'=>$newOwnerUserId],$reason);
            if(function_exists('account_admin_event'))account_admin_event($pdo,(int)$fresh['id'],(int)$actor['id'],$newOwnerUserId,'ownership_transferred',['owner_user_id'=>$oldOwner],['owner_user_id'=>$newOwnerUserId],$reason);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return account_admin_get($pdo,(int)$fresh['id'])??$fresh;
    });
}
function account_membership_revoke_all_pending(PDO $pdo,int $accountId,?int $actorUserId,string $reason): int {
    if(!account_membership_ready($pdo))return 0;$pending=account_membership_invitations($pdo,$accountId,true,500);if(!$pending)return 0;
    $q=$pdo->prepare("UPDATE account_invitations SET status='revoked',revoked_by_user_id=?,revoked_at=NOW() WHERE account_id=? AND status='pending'");$q->execute([$actorUserId,$accountId]);foreach($pending as $invite)account_membership_event($pdo,$accountId,$actorUserId,null,(int)$invite['id'],'invitation_revoked',['status'=>'pending'],['status'=>'revoked'],$reason);return $q->rowCount();
}
function account_membership_agent_context(PDO $pdo,array $viewer): string {
    if(!account_membership_ready($pdo))return '';$rows=account_membership_accounts_for_user($pdo,$viewer,false);if(!$rows)return '';
    $parts=["[COMMERCIAL ACCOUNT MEMBERSHIP — READ ONLY]\nCommercial account membership is separate from Research Teams. Do not claim membership changes were executed. Direct owners/admins to /account-members.php for explicit account changes."];
    foreach(array_slice($rows,0,10) as $account){$s=account_membership_seat_summary($pdo,(int)$account['id']);$parts[]='Account: '.(string)$account['name'].' ['.(string)$account['public_id'].'] · role '.(string)$account['account_role'].' · package '.(string)$account['package_name'].' · seats '.$s['used_seats'].'/'.$s['member_limit'].' ('.$s['member_count'].' members, '.$s['pending_reserved'].' pending reserved) · status '.(string)$account['status'].'/'.(string)$account['subscription_status'];}
    return implode("\n",$parts);
}
