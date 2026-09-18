<?php
declare(strict_types=1);

function moderation_target(PDO $pdo,string $type,string $publicId,?array $viewer): ?array {
    if(!in_array($type,['annotation','comment','live_message','user','source'],true)||$publicId==='')return null;
    if($type==='annotation'){
        $a=annotation_access($pdo,$publicId,$viewer);if(!$a)return null;
        return ['type'=>'annotation','public_id'=>$publicId,'owner_user_id'=>(int)$a['user_id'],'source_id'=>(int)$a['source_id']];
    }
    if($type==='comment'){
        $q=$pdo->prepare('SELECT c.*,a.public_id annotation_public_id,a.user_id annotation_user_id,a.source_id FROM comments c JOIN annotations a ON a.id=c.annotation_id WHERE c.public_id=? LIMIT 1');$q->execute([$publicId]);$c=$q->fetch();if(!$c)return null;
        if(!annotation_access($pdo,(string)$c['annotation_public_id'],$viewer))return null;
        return ['type'=>'comment','public_id'=>$publicId,'owner_user_id'=>(int)$c['user_id'],'annotation_public_id'=>$c['annotation_public_id'],'source_id'=>(int)$c['source_id'],'moderation_status'=>$c['moderation_status']??'visible'];
    }
    if($type==='live_message'){
        if(!$viewer)return null;
        $q=$pdo->prepare('SELECT lm.*,s.public_id source_public_id,t.public_id team_public_id,rp.public_id project_public_id FROM live_messages lm JOIN sources s ON s.id=lm.source_id LEFT JOIN teams t ON t.id=lm.team_id LEFT JOIN research_projects rp ON rp.id=lm.project_id WHERE lm.public_id=? LIMIT 1');$q->execute([$publicId]);$m=$q->fetch();if(!$m)return null;
        $roomId=$m['room_type']==='team'?$m['team_public_id']:($m['room_type']==='project'?$m['project_public_id']:null);
        $room=live_room_scope($pdo,$viewer,(string)$m['source_public_id'],(string)$m['room_type'],$roomId,false);if(!$room)return null;
        if(is_blocked($pdo,(int)$viewer['id'],(int)$m['user_id']))return null;
        return ['type'=>'live_message','public_id'=>$publicId,'owner_user_id'=>(int)$m['user_id'],'source_id'=>(int)$m['source_id'],'source_public_id'=>$m['source_public_id'],'room_type'=>$m['room_type'],'room_public_id'=>$roomId,'deleted'=>!empty($m['deleted_at'])];
    }
    if($type==='user'){
        $q=$pdo->prepare("SELECT u.id,u.public_id,u.status,COALESCE(p.profile_visibility,'public') profile_visibility FROM users u LEFT JOIN user_preferences p ON p.user_id=u.id WHERE u.public_id=? LIMIT 1");$q->execute([$publicId]);$target=$q->fetch();if(!$target)return null;
        if(!$viewer&&($target['profile_visibility']!=='public'||$target['status']!=='active'))return null;
        if($viewer&&is_blocked($pdo,(int)$viewer['id'],(int)$target['id']))return null;
        return ['type'=>'user','public_id'=>$publicId,'owner_user_id'=>(int)$target['id'],'status'=>$target['status']];
    }
    $s=source_access($pdo,$publicId,$viewer);if(!$s)return null;
    return ['type'=>'source','public_id'=>$publicId,'owner_user_id'=>null,'source_id'=>(int)$s['id'],'moderation_status'=>$s['moderation_status']??'visible'];
}
function moderation_report_create(PDO $pdo,?array $viewer,string $type,string $publicId,string $reason,?string $description=null): array {
    $reason=trim($reason);$description=trim((string)$description);if($reason===''||mb_strlen($reason)>80)throw new InvalidArgumentException('Choose a valid report reason.');
    $target=moderation_target($pdo,$type,$publicId,$viewer);if(!$target)throw new RuntimeException('Reported content is not available.');
    $reporterId=(int)($viewer['id']??0)?:null;
    if($reporterId){$q=$pdo->prepare("SELECT public_id FROM moderation_reports WHERE reported_by_user_id=? AND object_type=? AND object_public_id=? AND reason=? AND status IN ('open','under_review') ORDER BY id DESC LIMIT 1");$q->execute([$reporterId,$type,$publicId,$reason]);$existing=$q->fetchColumn();if($existing)return ['public_id'=>(string)$existing,'created'=>false];}
    $reportPublic=ulid_like();$pdo->prepare('INSERT INTO moderation_reports(public_id,reported_by_user_id,object_type,object_public_id,reason,description) VALUES(?,?,?,?,?,?)')->execute([$reportPublic,$reporterId,$type,$publicId,$reason,$description?:null]);
    try{$q=$pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'");foreach($q->fetchAll(PDO::FETCH_COLUMN) as $adminId)notification_create($pdo,(int)$adminId,$reporterId,'moderation_report_submitted','moderation_report',$reportPublic,'New '.$type.' report requires review.',['category'=>'moderation','dedupe_key'=>'report:'.$reportPublic,'group_key'=>'moderation:reports']);}catch(PDOException $e){}
    return ['public_id'=>$reportPublic,'created'=>true];
}
function moderation_action_record(PDO $pdo,array $admin,?int $reportId,?int $claimId,string $action,string $reason='',array $metadata=[]): void {
    $pdo->prepare('INSERT INTO moderation_actions(public_id,report_id,rights_claim_id,moderator_user_id,action_type,reason,metadata_json) VALUES(?,?,?,?,?,?,?)')->execute([ulid_like(),$reportId,$claimId,$admin['id'],$action,$reason?:null,$metadata?json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
}
function moderation_apply_object_action(PDO $pdo,array $admin,string $type,string $publicId,string $action): void {
    if(!in_array($action,['none','restrict','remove','restore','warn'],true))throw new InvalidArgumentException('Invalid moderation action.');
    if(in_array($action,['none','warn'],true))return;
    if($type==='annotation'){
        $status=$action==='restore'?'published':($action==='remove'?'removed':'restricted');$pdo->prepare('UPDATE annotations SET status=? WHERE public_id=?')->execute([$status,$publicId]);return;
    }
    if($type==='comment'){
        $status=$action==='restore'?'visible':($action==='remove'?'removed':'restricted');$pdo->prepare('UPDATE comments SET moderation_status=?,removed_at=?,removed_by_user_id=? WHERE public_id=?')->execute([$status,$status==='removed'?date('Y-m-d H:i:s'):null,$status==='removed'?$admin['id']:null,$publicId]);return;
    }
    if($type==='live_message'){
        if($action==='restore')$pdo->prepare('UPDATE live_messages SET deleted_at=NULL,deleted_by_user_id=NULL WHERE public_id=?')->execute([$publicId]);
        else $pdo->prepare('UPDATE live_messages SET deleted_at=COALESCE(deleted_at,NOW()),deleted_by_user_id=? WHERE public_id=?')->execute([$admin['id'],$publicId]);return;
    }
    if($type==='user'){
        $pdo->prepare('UPDATE users SET status=? WHERE public_id=?')->execute([$action==='restore'?'active':'suspended',$publicId]);return;
    }
    if($type==='source'){$pdo->prepare('UPDATE sources SET moderation_status=? WHERE public_id=?')->execute([$action==='restore'?'visible':'restricted',$publicId]);}
}
function moderation_report_update(PDO $pdo,array $admin,int $reportId,string $status,string $action='none',string $note=''): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Admin access required.');
    if(!in_array($status,['open','under_review','actioned','dismissed'],true))throw new InvalidArgumentException('Invalid report status.');
    if(!in_array($action,['none','restrict','remove','restore','warn'],true))throw new InvalidArgumentException('Invalid resolution action.');
    $q=$pdo->prepare('SELECT * FROM moderation_reports WHERE id=? FOR UPDATE');$q->execute([$reportId]);$r=$q->fetch();if(!$r)throw new RuntimeException('Report not found.');
    if($action!=='none')moderation_apply_object_action($pdo,$admin,(string)$r['object_type'],(string)$r['object_public_id'],$action);
    $pdo->prepare("UPDATE moderation_reports SET status=?,moderator_note=?,resolution_action=?,resolved_at=IF(? IN ('actioned','dismissed'),NOW(),NULL) WHERE id=?")->execute([$status,$note?:null,$action,$status,$reportId]);
    moderation_action_record($pdo,$admin,$reportId,null,'report_'.$status,$note,['resolution_action'=>$action,'object_type'=>$r['object_type'],'object_public_id'=>$r['object_public_id']]);
    if($r['reported_by_user_id'])notification_create($pdo,(int)$r['reported_by_user_id'],(int)$admin['id'],'moderation_report_updated','moderation_report',(string)$r['public_id'],'Your report is now '.str_replace('_',' ',$status).'.',['category'=>'moderation','dedupe_key'=>'report-status:'.$r['id'].':'.$status.':'.$action,'group_key'=>'report:'.$r['public_id']]);
    $target=moderation_target($pdo,(string)$r['object_type'],(string)$r['object_public_id'],$admin);if($target&&!empty($target['owner_user_id'])&&(int)$target['owner_user_id']!==(int)$admin['id']&&in_array($action,['restrict','remove','restore','warn'],true))notification_create($pdo,(int)$target['owner_user_id'],(int)$admin['id'],'moderation_action',(string)$r['object_type'],(string)$r['object_public_id'],'A moderation action was applied to your '.$r['object_type'].': '.$action.'.',['category'=>'moderation','dedupe_key'=>'moderation-action:'.$reportId.':'.$action,'group_key'=>'moderation-object:'.$r['object_type'].':'.$r['object_public_id']]);
    return ['public_id'=>$r['public_id'],'status'=>$status,'resolution_action'=>$action];
}
function rights_claim_token(): string {return bin2hex(random_bytes(24));}
function rights_claim_create(PDO $pdo,?array $viewer,string $annotationPublicId,string $name,string $email,string $claimType,string $description): array {
    $name=trim($name);$email=trim($email);$description=trim($description);if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||$description==='')throw new InvalidArgumentException('Complete all required fields.');
    if(!in_array($claimType,['copyright','ownership','incorrect_attribution','inaccurate_context','privacy','impersonation','other'],true))$claimType='other';
    $q=$pdo->prepare("SELECT id,user_id,public_id FROM annotations WHERE public_id=? AND visibility='public' AND status='published' LIMIT 1");$q->execute([$annotationPublicId]);$a=$q->fetch();if(!$a)throw new RuntimeException('Annotation not found.');
    $public=ulid_like();$token=rights_claim_token();$hash=hash('sha256',$token);$claimantUserId=(int)($viewer['id']??0)?:null;
    $pdo->prepare('INSERT INTO rights_claims(public_id,annotation_id,claimant_user_id,claimant_name,claimant_email,tracking_token_hash,claim_type,description) VALUES(?,?,?,?,?,?,?,?)')->execute([$public,$a['id'],$claimantUserId,$name,$email,$hash,$claimType,$description]);$id=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO rights_claim_events(public_id,rights_claim_id,actor_user_id,event_type,status,note) VALUES(?,?,?,'submitted','submitted',?)")->execute([ulid_like(),$id,$claimantUserId,'Claim submitted for human review.']);
    try{$q=$pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'");foreach($q->fetchAll(PDO::FETCH_COLUMN) as $adminId)notification_create($pdo,(int)$adminId,$claimantUserId,'claim_submitted','rights_claim',$public,'New rights claim requires review.',['category'=>'claims','dedupe_key'=>'claim:'.$public,'group_key'=>'claims:open']);}catch(PDOException $e){}
    return ['public_id'=>$public,'tracking_token'=>$token,'id'=>$id];
}
function rights_claim_access(PDO $pdo,string $publicId,?array $viewer,?string $token=null): ?array {
    $q=$pdo->prepare('SELECT rc.*,a.public_id annotation_public_id,a.user_id annotation_user_id,s.public_id source_public_id,s.canonical_url FROM rights_claims rc JOIN annotations a ON a.id=rc.annotation_id JOIN sources s ON s.id=a.source_id WHERE rc.public_id=? LIMIT 1');$q->execute([$publicId]);$c=$q->fetch();if(!$c)return null;
    if($viewer&&(($viewer['role']??'')==='admin'||(int)$c['claimant_user_id']===(int)$viewer['id']))return $c;
    if($token&&$c['tracking_token_hash']&&hash_equals((string)$c['tracking_token_hash'],hash('sha256',$token)))return $c;
    return null;
}
function rights_claim_events(PDO $pdo,int $claimId): array {$q=$pdo->prepare('SELECT event_type,status,note,created_at FROM rights_claim_events WHERE rights_claim_id=? ORDER BY id ASC');$q->execute([$claimId]);return $q->fetchAll();}
function rights_claim_update(PDO $pdo,array $admin,int $claimId,string $status,string $note='',string $decision=''): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Admin access required.');
    if(!in_array($status,['submitted','under_review','resolved','rejected','restricted','appealed','reopened'],true))throw new InvalidArgumentException('Invalid claim status.');
    $q=$pdo->prepare('SELECT rc.*,a.public_id annotation_public_id,a.user_id annotation_user_id FROM rights_claims rc JOIN annotations a ON a.id=rc.annotation_id WHERE rc.id=? FOR UPDATE');$q->execute([$claimId]);$c=$q->fetch();if(!$c)throw new RuntimeException('Claim not found.');
    $pdo->prepare("UPDATE rights_claims SET status=?,moderator_note=?,decision_summary=?,resolved_at=IF(? IN ('resolved','rejected','restricted'),NOW(),NULL) WHERE id=?")->execute([$status,$note?:null,$decision?:null,$status,$claimId]);
    if($status==='restricted')$pdo->prepare("UPDATE annotations SET status='restricted' WHERE id=?")->execute([$c['annotation_id']]);
    if(in_array($status,['resolved','rejected'],true)&&$c['status']==='restricted')$pdo->prepare("UPDATE annotations SET status='published' WHERE id=? AND status='restricted'")->execute([$c['annotation_id']]);
    $eventType=$status==='reopened'?'reopened':'status_changed';$pdo->prepare('INSERT INTO rights_claim_events(public_id,rights_claim_id,actor_user_id,event_type,status,note) VALUES(?,?,?,?,?,?)')->execute([ulid_like(),$claimId,$admin['id'],$eventType,$status,$decision?:$note?:null]);
    moderation_action_record($pdo,$admin,null,$claimId,'rights_claim_'.$status,$note,['decision_summary'=>$decision]);
    if($c['claimant_user_id'])notification_create($pdo,(int)$c['claimant_user_id'],(int)$admin['id'],'claim_status','rights_claim',(string)$c['public_id'],'Your claim is now '.str_replace('_',' ',$status).'.',['category'=>'claims','dedupe_key'=>'claim-status:'.$claimId.':'.$status,'group_key'=>'claim:'.$c['public_id']]);
    if($status==='restricted'&&(int)$c['annotation_user_id']!==(int)$admin['id'])notification_create($pdo,(int)$c['annotation_user_id'],(int)$admin['id'],'moderation_action','annotation',(string)$c['annotation_public_id'],'An annotation was restricted while a rights claim is reviewed.',['category'=>'moderation','dedupe_key'=>'claim-restrict:'.$claimId,'group_key'=>'annotation:'.$c['annotation_public_id']]);
    return ['public_id'=>$c['public_id'],'status'=>$status];
}
function rights_claim_appeal(PDO $pdo,array $claim,?array $viewer,string $note): void {
    $note=trim($note);if($note===''||mb_strlen($note)>5000)throw new InvalidArgumentException('Add a short appeal explanation.');
    if(!in_array($claim['status'],['resolved','rejected','restricted'],true))throw new RuntimeException('This claim cannot be appealed in its current state.');
    $pdo->prepare("UPDATE rights_claims SET status='appealed',resolved_at=NULL WHERE id=?")->execute([$claim['id']]);
    $pdo->prepare("INSERT INTO rights_claim_events(public_id,rights_claim_id,actor_user_id,event_type,status,note) VALUES(?,?,?,'appeal','appealed',?)")->execute([ulid_like(),$claim['id'],$viewer['id']??null,$note]);
    try{$q=$pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'");foreach($q->fetchAll(PDO::FETCH_COLUMN) as $adminId)notification_create($pdo,(int)$adminId,$viewer['id']??null,'claim_appealed','rights_claim',(string)$claim['public_id'],'A rights claim was appealed.',['category'=>'claims','dedupe_key'=>'claim-appeal:'.$claim['id'].':'.time(),'group_key'=>'claim:'.$claim['public_id']]);}catch(PDOException $e){}
}
