<?php
declare(strict_types=1);

function admin_support_ready(PDO $pdo): bool {
    try{
        foreach(['admin_support_cases','admin_support_case_events','admin_support_case_links','admin_support_saved_views'] as $table)if(!installer_table_exists($pdo,$table))return false;
        return true;
    }catch(Throwable $e){return false;}
}
function admin_support_reason(string $value,string $fallback='Support case updated.'): string {
    $value=trim($value);if($value==='')$value=$fallback;return mb_substr($value,0,1000);
}
function admin_support_sla_due(string $priority): string {
    $hours=match($priority){'urgent'=>4,'high'=>8,'low'=>72,default=>24};return gmdate('Y-m-d H:i:s',time()+($hours*3600));
}
function admin_support_case(PDO $pdo,string|int $id): ?array {
    if(!admin_support_ready($pdo))return null;
    if(is_int($id)||ctype_digit((string)$id)){$q=$pdo->prepare("SELECT c.*,a.public_id account_public_id,a.name account_name,u.public_id user_public_id,u.username customer_username,u.display_name customer_display_name,u.email customer_email,au.username assigned_username,au.display_name assigned_display_name,cu.username creator_username FROM admin_support_cases c LEFT JOIN accounts a ON a.id=c.account_id LEFT JOIN users u ON u.id=c.user_id LEFT JOIN users au ON au.id=c.assigned_user_id JOIN users cu ON cu.id=c.created_by_user_id WHERE c.id=? LIMIT 1");$q->execute([(int)$id]);}
    else{$q=$pdo->prepare("SELECT c.*,a.public_id account_public_id,a.name account_name,u.public_id user_public_id,u.username customer_username,u.display_name customer_display_name,u.email customer_email,au.username assigned_username,au.display_name assigned_display_name,cu.username creator_username FROM admin_support_cases c LEFT JOIN accounts a ON a.id=c.account_id LEFT JOIN users u ON u.id=c.user_id LEFT JOIN users au ON au.id=c.assigned_user_id JOIN users cu ON cu.id=c.created_by_user_id WHERE c.public_id=? LIMIT 1");$q->execute([(string)$id]);}
    return $q->fetch()?:null;
}
function admin_support_resolve_user(PDO $pdo,string $identifier): ?array {
    $identifier=trim($identifier);if($identifier==='')return null;
    $q=$pdo->prepare("SELECT id,public_id,username,display_name,email,status,role FROM users WHERE public_id=? OR username=? OR email=? LIMIT 1");$q->execute([$identifier,$identifier,$identifier]);return $q->fetch()?:null;
}
function admin_support_resolve_account(PDO $pdo,string $publicId): ?array {
    $publicId=trim($publicId);if($publicId==='')return null;return account_admin_get($pdo,$publicId);
}
function admin_support_event(PDO $pdo,int $caseId,?int $actorUserId,string $eventType,string $body='',string $visibility='internal',array $metadata=[]): void {
    if(!admin_support_ready($pdo))return;$visibility=$visibility==='customer'?'customer':'internal';$body=trim($body);$mentions=[];
    if($body!==''&&preg_match_all('/@([A-Za-z0-9_.-]{2,48})/',$body,$m))$mentions=array_values(array_unique($m[1]));
    if($mentions)$metadata['mentions']=$mentions;$json=$metadata?json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):null;
    $pdo->prepare("INSERT INTO admin_support_case_events(public_id,case_id,actor_user_id,event_type,visibility,body,metadata_json) VALUES(?,?,?,?,?,?,?)")->execute([ulid_like(),$caseId,$actorUserId,mb_substr($eventType,0,80),$visibility,$body!==''?$body:null,$json]);
    if($mentions&&function_exists('notification_create')){$q=$pdo->prepare("SELECT public_id,title FROM admin_support_cases WHERE id=?");$q->execute([$caseId]);$case=$q->fetch();if($case){foreach($mentions as $username){$u=$pdo->prepare("SELECT id,public_id,username,display_name,email,role,status FROM users WHERE username=? AND role='admin' AND status='active' LIMIT 1");$u->execute([$username]);$target=$u->fetch();if(!$target||!function_exists('admin_access_has_capability')||!admin_access_has_capability($pdo,$target,'admin.support.view'))continue;notification_create($pdo,(int)$target['id'],$actorUserId,'support_mention','support_case',(string)$case['public_id'],'Mentioned in support case: '.(string)$case['title'],['dedupe_key'=>'support-mention-'.$case['public_id'].'-'.$username.'-'.hash('sha256',$body),'group_key'=>'support-case-'.$case['public_id']]);}}}
}
function admin_support_events(PDO $pdo,int $caseId,int $limit=250): array {
    if(!admin_support_ready($pdo))return [];$limit=max(1,min(500,$limit));$q=$pdo->prepare("SELECT e.*,u.username actor_username,u.display_name actor_display_name FROM admin_support_case_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.case_id=? ORDER BY e.created_at DESC,e.id DESC LIMIT ".$limit);$q->execute([$caseId]);return $q->fetchAll()?:[];
}
function admin_support_links(PDO $pdo,int $caseId): array {
    if(!admin_support_ready($pdo))return [];$q=$pdo->prepare("SELECT l.*,u.username creator_username FROM admin_support_case_links l JOIN users u ON u.id=l.created_by_user_id WHERE l.case_id=? ORDER BY l.created_at DESC,l.id DESC");$q->execute([$caseId]);return $q->fetchAll()?:[];
}
function admin_support_link(PDO $pdo,array $admin,int $caseId,string $type,string $linkedId,string $label='',string $url=''): void {
    admin_access_assert_capability($pdo,$admin,'admin.support.manage');$type=mb_substr(trim($type),0,80);$linkedId=mb_substr(trim($linkedId),0,255);if($type===''||$linkedId==='')throw new InvalidArgumentException('Link type and identifier are required.');$label=mb_substr(trim($label),0,255);$url=mb_substr(trim($url),0,500);
    $pdo->prepare("INSERT INTO admin_support_case_links(public_id,case_id,link_type,linked_public_id,label,target_url,created_by_user_id) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),target_url=VALUES(target_url)")->execute([ulid_like(),$caseId,$type,$linkedId,$label!==''?$label:null,$url!==''?$url:null,(int)$admin['id']]);
}
function admin_support_duplicate_candidates(PDO $pdo,?int $accountId,?int $userId,string $category,string $title,int $limit=8): array {
    if(!admin_support_ready($pdo))return [];$limit=max(1,min(20,$limit));$q=$pdo->prepare("SELECT c.public_id,c.title,c.status,c.priority,c.updated_at,a.name account_name FROM admin_support_cases c LEFT JOIN accounts a ON a.id=c.account_id WHERE c.status NOT IN ('resolved','closed') AND c.account_id <=> ? AND c.user_id <=> ? AND c.category=? AND LOWER(c.title)=LOWER(?) ORDER BY c.updated_at DESC,c.id DESC LIMIT ".$limit);$q->execute([$accountId,$userId,$category,$title]);return $q->fetchAll()?:[];
}
function admin_support_create(PDO $pdo,array $admin,array $input): array {
    admin_access_assert_capability($pdo,$admin,'admin.support.manage');if(!admin_support_ready($pdo))throw new RuntimeException('Admin V2.20 Support Operations requires migration 075.');
    $account=admin_support_resolve_account($pdo,(string)($input['account_public_id']??''));$user=admin_support_resolve_user($pdo,(string)($input['user_identifier']??''));
    if(!$user&&$account&&!empty($account['owner_user_id'])){$q=$pdo->prepare("SELECT id,public_id,username,display_name,email,status,role FROM users WHERE id=?");$q->execute([(int)$account['owner_user_id']]);$user=$q->fetch()?:null;}
    if(!$account&&$user){$q=$pdo->prepare("SELECT a.* FROM accounts a WHERE a.personal_user_id=? OR a.owner_user_id=? ORDER BY a.account_type='personal' DESC,a.id LIMIT 1");$q->execute([(int)$user['id'],(int)$user['id']]);$account=$q->fetch()?:null;}
    $title=mb_substr(trim((string)($input['title']??'')),0,255);if($title==='')throw new InvalidArgumentException('Case title is required.');
    $category=preg_replace('/[^a-z0-9_\-]+/','_',strtolower(trim((string)($input['category']??'general'))))?:'general';$category=mb_substr(trim($category,'_'),0,80)?:'general';
    $priority=in_array((string)($input['priority']??'normal'),['low','normal','high','urgent'],true)?(string)$input['priority']:'normal';$source=in_array((string)($input['source']??'admin'),['admin','customer','system','alert'],true)?(string)$input['source']:'admin';$description=admin_support_reason((string)($input['description']??''),'Support case created.');
    $duplicates=admin_support_duplicate_candidates($pdo,$account?(int)$account['id']:null,$user?(int)$user['id']:null,$category,$title);
    $assigned=!empty($input['assigned_user_id'])?(int)$input['assigned_user_id']:null;if($assigned!==null&&!admin_support_admin_is_capable($pdo,$assigned,'admin.support.manage'))throw new RuntimeException('Assigned operator does not have Support manage capability.');
    $status=$assigned?'assigned':'open';$pdo->prepare("INSERT INTO admin_support_cases(public_id,account_id,user_id,created_by_user_id,assigned_user_id,title,category,priority,status,source,sla_due_at,last_operator_activity_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW())")->execute([ulid_like(),$account?(int)$account['id']:null,$user?(int)$user['id']:null,(int)$admin['id'],$assigned,$title,$category,$priority,$status,$source,admin_support_sla_due($priority)]);$id=(int)$pdo->lastInsertId();if($source==='customer')$pdo->prepare("UPDATE admin_support_cases SET last_customer_activity_at=NOW() WHERE id=?")->execute([$id]);
    $case=admin_support_case($pdo,$id)??throw new RuntimeException('Support case could not be reloaded.');admin_support_event($pdo,$id,(int)$admin['id'],'case_created',$description,'internal',['priority'=>$priority,'category'=>$category,'duplicate_candidates'=>array_column($duplicates,'public_id')]);
    if($account)admin_support_link($pdo,$admin,$id,'account',(string)$account['public_id'],(string)$account['name'],'/admin/account.php?id='.rawurlencode((string)$account['public_id']));
    if($user)admin_support_link($pdo,$admin,$id,'user',(string)$user['public_id'],(string)($user['display_name']?:$user['username']),'/admin/users.php?q='.rawurlencode((string)$user['username']));
    $case['duplicate_candidates']=$duplicates;return $case;
}
function admin_support_admin_is_capable(PDO $pdo,int $userId,string $capability): bool {
    $q=$pdo->prepare("SELECT id,public_id,username,display_name,email,role,status FROM users WHERE id=? AND role='admin' AND status='active'");$q->execute([$userId]);$u=$q->fetch();return $u?admin_access_has_capability($pdo,$u,$capability):false;
}
function admin_support_admin_candidates(PDO $pdo,string $capability='admin.support.manage'): array {
    $rows=$pdo->query("SELECT id,public_id,username,display_name,email,role,status FROM users WHERE role='admin' AND status='active' ORDER BY display_name,username,id")->fetchAll()?:[];$out=[];foreach($rows as $row)if(admin_access_has_capability($pdo,$row,$capability))$out[]=$row;return $out;
}
function admin_support_filters(array $input): array {
    $out=[];foreach(['status','priority','category','assigned','q'] as $key){$v=trim((string)($input[$key]??''));if($v!=='')$out[$key]=$v;}if(!empty($input['sla_risk']))$out['sla_risk']='1';return $out;
}
function admin_support_cases(PDO $pdo,array $viewer,array $filters=[],int $limit=250): array {
    if(!admin_support_ready($pdo))return [];$limit=max(1,min(500,$limit));$filters=admin_support_filters($filters);$where=[];$params=[];
    if(isset($filters['status'])){$where[]='c.status=?';$params[]=$filters['status'];}
    if(isset($filters['priority'])){$where[]='c.priority=?';$params[]=$filters['priority'];}
    if(isset($filters['category'])){$where[]='c.category=?';$params[]=$filters['category'];}
    if(isset($filters['assigned'])){if($filters['assigned']==='me'){$where[]='c.assigned_user_id=?';$params[]=(int)$viewer['id'];}elseif($filters['assigned']==='unassigned')$where[]='c.assigned_user_id IS NULL';elseif(ctype_digit($filters['assigned'])){$where[]='c.assigned_user_id=?';$params[]=(int)$filters['assigned'];}}
    if(isset($filters['sla_risk']))$where[]="c.status NOT IN ('resolved','closed') AND c.sla_due_at IS NOT NULL AND c.sla_due_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 4 HOUR)";
    if(isset($filters['q'])){$like='%'.$filters['q'].'%';$where[]='(c.public_id LIKE ? OR c.title LIKE ? OR a.name LIKE ? OR u.email LIKE ? OR u.username LIKE ?)';array_push($params,$like,$like,$like,$like,$like);}
    $sql="SELECT c.*,a.public_id account_public_id,a.name account_name,u.username customer_username,u.display_name customer_display_name,u.email customer_email,au.username assigned_username,au.display_name assigned_display_name FROM admin_support_cases c LEFT JOIN accounts a ON a.id=c.account_id LEFT JOIN users u ON u.id=c.user_id LEFT JOIN users au ON au.id=c.assigned_user_id".($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY FIELD(c.priority,'urgent','high','normal','low'),CASE WHEN c.status IN ('resolved','closed') THEN 1 ELSE 0 END,c.sla_due_at IS NULL,c.sla_due_at,c.updated_at DESC,c.id DESC LIMIT ".$limit;$q=$pdo->prepare($sql);$q->execute($params);return $q->fetchAll()?:[];
}
function admin_support_saved_views(PDO $pdo,array $admin): array {
    if(!admin_support_ready($pdo))return [];$q=$pdo->prepare("SELECT * FROM admin_support_saved_views WHERE user_id=? ORDER BY is_default DESC,name,id");$q->execute([(int)$admin['id']]);return $q->fetchAll()?:[];
}
function admin_support_saved_view_save(PDO $pdo,array $admin,string $name,array $filters,bool $default=false): array {
    admin_access_assert_capability($pdo,$admin,'admin.support.view');$name=mb_substr(trim($name),0,120);if($name==='')throw new InvalidArgumentException('Saved view name is required.');$clean=admin_support_filters($filters);$json=json_encode($clean,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $pdo->beginTransaction();try{if($default)$pdo->prepare("UPDATE admin_support_saved_views SET is_default=0 WHERE user_id=?")->execute([(int)$admin['id']]);$pid=ulid_like();$pdo->prepare("INSERT INTO admin_support_saved_views(public_id,user_id,name,filters_json,is_default) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE filters_json=VALUES(filters_json),is_default=VALUES(is_default),updated_at=NOW()")->execute([$pid,(int)$admin['id'],$name,$json,$default?1:0]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $q=$pdo->prepare("SELECT * FROM admin_support_saved_views WHERE user_id=? AND name=?");$q->execute([(int)$admin['id'],$name]);return $q->fetch()?:[];
}
function admin_support_saved_view_delete(PDO $pdo,array $admin,string $publicId): void {
    admin_access_assert_capability($pdo,$admin,'admin.support.view');$q=$pdo->prepare("DELETE FROM admin_support_saved_views WHERE public_id=? AND user_id=?");$q->execute([$publicId,(int)$admin['id']]);
}
function admin_support_update(PDO $pdo,array $admin,string $casePublicId,string $operation,array $input=[]): array {
    admin_access_assert_capability($pdo,$admin,'admin.support.manage');$case=admin_support_case($pdo,$casePublicId);if(!$case)throw new RuntimeException('Support case not found.');$id=(int)$case['id'];$reason=admin_support_reason((string)($input['reason']??''));$before=['status'=>$case['status'],'priority'=>$case['priority'],'assigned_user_id'=>$case['assigned_user_id'],'escalation_team'=>$case['escalation_team']];
    if($operation==='assign'){$uid=(int)($input['user_id']??0);if($uid<1||!admin_support_admin_is_capable($pdo,$uid,'admin.support.manage'))throw new RuntimeException('Select an active Support-capable Admin operator.');$pdo->prepare("UPDATE admin_support_cases SET assigned_user_id=?,status=CASE WHEN status='open' THEN 'assigned' ELSE status END,last_operator_activity_at=NOW() WHERE id=?")->execute([$uid,$id]);admin_support_event($pdo,$id,(int)$admin['id'],'case_assigned',$reason,'internal',['assigned_user_id'=>$uid]);if(function_exists('notification_create'))notification_create($pdo,$uid,(int)$admin['id'],'support_assigned','support_case',$casePublicId,'Support case assigned: '.(string)$case['title'],['dedupe_key'=>'support-assigned-'.$casePublicId.'-'.$uid.'-'.hash('sha256',$reason),'group_key'=>'support-case-'.$casePublicId]);}
    elseif($operation==='priority'){$priority=in_array((string)($input['priority']??''),['low','normal','high','urgent'],true)?(string)$input['priority']:throw new InvalidArgumentException('Invalid priority.');$pdo->prepare("UPDATE admin_support_cases SET priority=?,sla_due_at=?,last_operator_activity_at=NOW() WHERE id=?")->execute([$priority,admin_support_sla_due($priority),$id]);admin_support_event($pdo,$id,(int)$admin['id'],'priority_changed',$reason,'internal',['priority'=>$priority]);}
    elseif($operation==='status'){$status=in_array((string)($input['status']??''),['open','assigned','waiting_customer','waiting_internal','escalated','resolved','closed'],true)?(string)$input['status']:throw new InvalidArgumentException('Invalid case status.');$was=(string)$case['status'];$sql="UPDATE admin_support_cases SET status=?,last_operator_activity_at=NOW(),resolved_at=CASE WHEN ?='resolved' THEN NOW() WHEN ?='resolved' AND ?<>'resolved' THEN NULL ELSE resolved_at END,closed_at=CASE WHEN ?='closed' THEN NOW() WHEN ?='closed' AND ?<>'closed' THEN NULL ELSE closed_at END WHERE id=?";$pdo->prepare($sql)->execute([$status,$status,$was,$status,$status,$was,$status,$id]);admin_support_event($pdo,$id,(int)$admin['id'],$status==='resolved'?'case_resolved':($status==='closed'?'case_closed':'status_changed'),$reason,'internal',['status'=>$status]);}
    elseif($operation==='escalate'){$team=preg_replace('/[^a-z0-9_\-]+/','_',strtolower(trim((string)($input['team']??''))))?:'';if($team==='')throw new InvalidArgumentException('Escalation team is required.');$pdo->prepare("UPDATE admin_support_cases SET status='escalated',escalation_team=?,escalation_reason=?,last_operator_activity_at=NOW() WHERE id=?")->execute([mb_substr($team,0,80),mb_substr($reason,0,500),$id]);admin_support_event($pdo,$id,(int)$admin['id'],'case_escalated',$reason,'internal',['team'=>$team]);}
    elseif($operation==='resolve'){$summary=admin_support_reason((string)($input['resolution_summary']??$reason),'Support case resolved.');$pdo->prepare("UPDATE admin_support_cases SET status='resolved',resolution_summary=?,resolved_at=NOW(),first_response_at=COALESCE(first_response_at,NOW()),last_operator_activity_at=NOW() WHERE id=?")->execute([$summary,$id]);admin_support_event($pdo,$id,(int)$admin['id'],'case_resolved',$summary,'internal');}
    elseif($operation==='reopen'){$pdo->prepare("UPDATE admin_support_cases SET status='open',resolved_at=NULL,closed_at=NULL,resolution_summary=NULL,last_operator_activity_at=NOW(),sla_due_at=? WHERE id=?")->execute([admin_support_sla_due((string)$case['priority']),$id]);admin_support_event($pdo,$id,(int)$admin['id'],'case_reopened',$reason,'internal');}
    elseif($operation==='duplicate'){$target=admin_support_case($pdo,(string)($input['duplicate_of']??''));if(!$target||(int)$target['id']===$id)throw new RuntimeException('Select a different canonical case.');$pdo->prepare("UPDATE admin_support_cases SET duplicate_of_case_id=?,status='closed',closed_at=NOW(),resolution_summary=?,last_operator_activity_at=NOW() WHERE id=?")->execute([(int)$target['id'],'Duplicate of '.$target['public_id'],$id]);admin_support_event($pdo,$id,(int)$admin['id'],'case_marked_duplicate',$reason,'internal',['duplicate_of'=>$target['public_id']]);}
    elseif($operation==='note'||$operation==='communication'){$body=admin_support_reason((string)($input['body']??''),'Support update.');$visibility=$operation==='communication'?'customer':'internal';$eventType=$operation==='communication'?'customer_communication':'internal_note';admin_support_event($pdo,$id,(int)$admin['id'],$eventType,$body,$visibility);if($operation==='communication')$pdo->prepare("UPDATE admin_support_cases SET first_response_at=COALESCE(first_response_at,NOW()),last_operator_activity_at=NOW() WHERE id=?")->execute([$id]);else $pdo->prepare("UPDATE admin_support_cases SET last_operator_activity_at=NOW() WHERE id=?")->execute([$id]);}
    elseif($operation==='link'){admin_support_link($pdo,$admin,$id,(string)($input['link_type']??'other'),(string)($input['linked_public_id']??''),(string)($input['label']??''),(string)($input['target_url']??''));admin_support_event($pdo,$id,(int)$admin['id'],'record_linked',$reason,'internal',['link_type'=>(string)($input['link_type']??'other'),'linked_public_id'=>(string)($input['linked_public_id']??'')]);}
    else throw new RuntimeException('Unknown support case operation.');
    $after=admin_support_case($pdo,$casePublicId)??$case;if(function_exists('admin_access_security_audit')&&admin_access_ready($pdo))admin_access_security_audit($pdo,$admin,'action',$casePublicId,'support_'.$operation,$before,['status'=>$after['status'],'priority'=>$after['priority'],'assigned_user_id'=>$after['assigned_user_id'],'escalation_team'=>$after['escalation_team']],$reason);return $after;
}
function admin_support_case_timeline(PDO $pdo,array $case,int $limit=300,?array $viewer=null): array {
    $rows=[];foreach(admin_support_events($pdo,(int)$case['id'],$limit) as $e)$rows[]=['created_at'=>$e['created_at'],'source'=>'support','title'=>$e['event_type'],'detail'=>$e['body']??'','actor'=>$e['actor_username']??'','visibility'=>$e['visibility']];
    if(!empty($case['account_id'])&&function_exists('admin_ops_account_timeline'))foreach(admin_ops_account_timeline($pdo,(int)$case['account_id'],$limit,$viewer) as $e){$e['visibility']='internal';$rows[]=$e;}
    usort($rows,fn($a,$b)=>strcmp((string)$b['created_at'],(string)$a['created_at']));return array_slice($rows,0,max(1,min(500,$limit)));
}
function admin_support_customer_360(PDO $pdo,array $case,?array $viewer=null): array {
    $ops=!empty($case['account_id'])&&function_exists('admin_ops_account_360')?admin_ops_account_360($pdo,(int)$case['account_id'],$viewer):null;$user=null;if(!empty($case['user_id'])){$q=$pdo->prepare("SELECT id,public_id,username,display_name,email,status,role,created_at FROM users WHERE id=?");$q->execute([(int)$case['user_id']]);$user=$q->fetch()?:null;}
    $members=[];if(!empty($case['account_id'])&&function_exists('account_admin_members'))try{$members=account_admin_members($pdo,(int)$case['account_id']);}catch(Throwable $e){}
    return ['user'=>$user,'operations'=>$ops,'members'=>$members,'links'=>admin_support_links($pdo,(int)$case['id']),'timeline'=>admin_support_case_timeline($pdo,$case,300,$viewer)];
}
function admin_support_metrics(PDO $pdo,array $viewer): array {
    if(!admin_support_ready($pdo))return [];$scalar=fn(string $sql)=>(int)($pdo->query($sql)->fetchColumn()?:0);
    $open=$scalar("SELECT COUNT(*) FROM admin_support_cases WHERE status NOT IN ('resolved','closed')");
    $mineQ=$pdo->prepare("SELECT COUNT(*) FROM admin_support_cases WHERE assigned_user_id=? AND status NOT IN ('resolved','closed')");$mineQ->execute([(int)$viewer['id']]);$mine=(int)$mineQ->fetchColumn();
    $unassigned=$scalar("SELECT COUNT(*) FROM admin_support_cases WHERE assigned_user_id IS NULL AND status NOT IN ('resolved','closed')");
    $sla=$scalar("SELECT COUNT(*) FROM admin_support_cases WHERE status NOT IN ('resolved','closed') AND sla_due_at IS NOT NULL AND sla_due_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 4 HOUR)");
    $escalated=$scalar("SELECT COUNT(*) FROM admin_support_cases WHERE status='escalated'");
    $resolved7=$scalar("SELECT COUNT(*) FROM admin_support_cases WHERE resolved_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)");
    $reopened7=$scalar("SELECT COUNT(*) FROM admin_support_case_events WHERE event_type='case_reopened' AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)");
    $avg=$pdo->query("SELECT AVG(TIMESTAMPDIFF(MINUTE,created_at,resolved_at)) FROM admin_support_cases WHERE resolved_at IS NOT NULL AND resolved_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)")->fetchColumn();
    return ['open'=>$open,'mine'=>$mine,'unassigned'=>$unassigned,'sla_risk'=>$sla,'escalated'=>$escalated,'resolved_7d'=>$resolved7,'reopened_7d'=>$reopened7,'avg_resolution_minutes'=>$avg===null?null:(int)round((float)$avg)];
}
function admin_support_category_counts(PDO $pdo): array {
    if(!admin_support_ready($pdo))return [];$q=$pdo->query("SELECT category,COUNT(*) total,SUM(status NOT IN ('resolved','closed')) open_total FROM admin_support_cases GROUP BY category ORDER BY open_total DESC,total DESC,category");return $q->fetchAll()?:[];
}
function admin_support_workload(PDO $pdo): array {
    if(!admin_support_ready($pdo))return [];$q=$pdo->query("SELECT u.id,u.username,u.display_name,COUNT(c.id) open_cases,SUM(c.priority='urgent') urgent_cases FROM users u LEFT JOIN admin_support_cases c ON c.assigned_user_id=u.id AND c.status NOT IN ('resolved','closed') WHERE u.role='admin' AND u.status='active' GROUP BY u.id,u.username,u.display_name HAVING open_cases>0 ORDER BY urgent_cases DESC,open_cases DESC,u.display_name,u.username");return $q->fetchAll()?:[];
}
function admin_support_dashboard(PDO $pdo,array $viewer,array $filters=[]): array {
    if(!admin_support_ready($pdo))return ['ready'=>false,'cases'=>[],'metrics'=>[],'views'=>[]];$views=admin_support_saved_views($pdo,$viewer);if(!$filters){foreach($views as $view)if(!empty($view['is_default'])){try{$v=json_decode((string)$view['filters_json'],true,512,JSON_THROW_ON_ERROR);if(is_array($v))$filters=$v;}catch(Throwable $e){}break;}}
    return ['ready'=>true,'cases'=>admin_support_cases($pdo,$viewer,$filters,300),'metrics'=>admin_support_metrics($pdo,$viewer),'views'=>$views,'filters'=>admin_support_filters($filters),'operators'=>admin_support_admin_candidates($pdo),'categories'=>admin_support_category_counts($pdo),'workload'=>admin_support_workload($pdo)];
}
function admin_support_account_cases(PDO $pdo,int $accountId,int $limit=50): array {
    if(!admin_support_ready($pdo))return [];$limit=max(1,min(200,$limit));$q=$pdo->prepare("SELECT c.*,au.username assigned_username FROM admin_support_cases c LEFT JOIN users au ON au.id=c.assigned_user_id WHERE c.account_id=? ORDER BY CASE WHEN c.status IN ('resolved','closed') THEN 1 ELSE 0 END,c.updated_at DESC,c.id DESC LIMIT ".$limit);$q->execute([$accountId]);return $q->fetchAll()?:[];
}
function admin_support_agent_context(PDO $pdo,array $viewer): string {
    if(($viewer['role']??'')!=='admin'||!admin_support_ready($pdo)||!admin_access_has_capability($pdo,$viewer,'admin.support.view'))return '';$m=admin_support_metrics($pdo,$viewer);return "[ADMIN V2.20 SUPPORT — READ ONLY]\nOpen support cases: ".(int)$m['open']."; assigned to this operator: ".(int)$m['mine']."; unassigned: ".(int)$m['unassigned']."; SLA-risk: ".(int)$m['sla_risk']."; escalated: ".(int)$m['escalated'].". Support case data may be summarized and prioritized, but the Agent cannot create/update/assign/escalate/resolve cases, send customer communications, or execute governed Admin actions.";
}
