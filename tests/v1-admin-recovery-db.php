<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/app/admin-recovery.php';
$dsn=(string)getenv('DB_DSN');if($dsn==='')throw new RuntimeException('DB_DSN required.');
$pdo=new PDO($dsn,(string)getenv('DB_USER'),(string)getenv('DB_PASS'),[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$check=static function(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label.PHP_EOL;};
$tmp=sys_get_temp_dir().'/annotated-recovery-db-'.bin2hex(random_bytes(5));
$site=$tmp.'/public_html';mkdir($site,0700,true);
$key=installer_setup_token_path($site);$marker=$site.'/admin-password-reset.enable';$secret=bin2hex(random_bytes(32));
$pub=static fn(string $s):string=>$s.'-'.bin2hex(random_bytes(6));
$make=function(string $name,string $role)use($pdo,$pub):array{
    $public=$pub('recover');$username=$pub(strtolower($name));
    $pdo->prepare('INSERT INTO users(public_id,username,display_name,email,password_hash,role) VALUES(?,?,?,?,?,?)')
       ->execute([$public,$username,$name,$username.'@example.test',password_hash('OriginalSecret012345',PASSWORD_DEFAULT),$role]);
    return ['id'=>(int)$pdo->lastInsertId(),'public_id'=>$public];
};
try{
    $admin=$make('RecoveryAdmin','admin');$other=$make('RecoveryOther','user');
    $original=(string)$pdo->query('SELECT password_hash FROM users WHERE id='.(int)$admin['id'])->fetchColumn();
    $insert=$pdo->prepare("INSERT INTO extension_sessions(user_id,token_hash,device_name,expires_at) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 3 DAY))");
    $insert->execute([$admin['id'],hash('sha256',$pub('token')),'Before Recovery']);
    $adminExtension=(int)$pdo->lastInsertId();
    $insert->execute([$other['id'],hash('sha256',$pub('token')),'Unrelated Account']);
    $otherExtension=(int)$pdo->lastInsertId();
    $check(!admin_recovery_owner_valid($site,$secret),'Owner cannot recover with no marker and key.');
    file_put_contents($marker,'');file_put_contents($key,$secret."\n");chmod($key,0600);
    $check(admin_recovery_owner_valid($site,$secret),'Authorized server owner can open recovery flow.');
    $check(!admin_recovery_owner_valid($site,str_repeat('0',64)),'Incorrect token fails even with marker.');
    $denied=false;try{admin_recovery_apply($pdo,(int)$other['id'],'AnotherPassword012345');}catch(RuntimeException $e){$denied=true;}
    $check($denied,'Non-admin account cannot be modified by emergency recovery.');
    $still=(string)$pdo->query('SELECT password_hash FROM users WHERE id='.(int)$admin['id'])->fetchColumn();
    $check(hash_equals($original,$still),'Unauthorized recovery does not affect administrator credentials.');
    admin_recovery_apply($pdo,(int)$admin['id'],'NewVerifiedPassword012345');
    $row=$pdo->query('SELECT password_hash,sessions_revoked_before FROM users WHERE id='.(int)$admin['id'])->fetch();
    $check(password_verify('NewVerifiedPassword012345',(string)$row['password_hash'])&&!empty($row['sessions_revoked_before']),
      'Authorized recovery updates the password and durable browser-session revocation cutoff.');
    $q=$pdo->prepare('SELECT revoked_at FROM extension_sessions WHERE id=?');
    $q->execute([$adminExtension]);$check($q->fetchColumn()!==null,'Administrator extension token revoked in same transaction.');
    $q->execute([$otherExtension]);$check($q->fetchColumn()===null,'Unrelated extension session is unchanged.');
    // Force the second update to fail. Both user credential and revocation must roll back.
    $insert->execute([$admin['id'],hash('sha256',$pub('token')),'Rollback Test']);
    $beforeRollback=(string)$pdo->query('SELECT password_hash FROM users WHERE id='.(int)$admin['id'])->fetchColumn();
    $trigger='recfail_'.bin2hex(random_bytes(3));
    $pdo->exec("CREATE TRIGGER ".$trigger." BEFORE UPDATE ON extension_sessions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='recovery rollback probe'");
    try{
        $failed=false;try{admin_recovery_apply($pdo,(int)$admin['id'],'MustNotPersist012345');}catch(PDOException $e){$failed=true;}
        $check($failed,'Extension revocation failure must abort the password update.');
        $afterRollback=(string)$pdo->query('SELECT password_hash FROM users WHERE id='.(int)$admin['id'])->fetchColumn();
        $check(hash_equals($beforeRollback,$afterRollback),'Recovery transaction rolls back the password after revocation failure.');
    }finally{$pdo->exec('DROP TRIGGER '.$trigger);}
    @unlink($key);
    $check(!admin_recovery_owner_valid($site,$secret),'Consuming the one-time external key disables further recovery.');
}finally{
    if(is_file($key))@unlink($key);if(is_file($marker))@unlink($marker);
    @rmdir($site);@rmdir($tmp);
}
echo "V1 administrator recovery DB and atomic rollback journey passed.\n";
