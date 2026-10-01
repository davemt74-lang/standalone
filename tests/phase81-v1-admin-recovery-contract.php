<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/app/admin-recovery.php';
$failed=[];
$check=static function(bool $ok,string $label)use(&$failed):void{if(!$ok)$failed[]=$label;};
$tmp=sys_get_temp_dir().'/annotated-recovery-contract-'.bin2hex(random_bytes(6));
$site=$tmp.'/public_html';
if(!mkdir($site,0700,true))throw new RuntimeException('Recovery fixture directory unavailable.');
$key=installer_setup_token_path($site);
$marker=$site.'/admin-password-reset.enable';
$secret=bin2hex(random_bytes(32));
try{
    $check(!admin_recovery_enabled($site),'Recovery disabled with no owner files.');
    $check(!admin_recovery_owner_valid($site,$secret),'No marker or key fails closed.');
    file_put_contents($marker,'',LOCK_EX);
    $check(!admin_recovery_enabled($site),'Empty enable marker alone cannot activate recovery.');
    file_put_contents($key,$secret."\n",LOCK_EX);chmod($key,0600);
    $check(admin_recovery_enabled($site),'Both owner-controlled files activate recovery.');
    $check(!admin_recovery_owner_valid($site,str_repeat('0',64)),'Incorrect high-entropy proof cannot authorize recovery.');
    $check(admin_recovery_owner_valid($site,$secret),'Correct proof plus marker authorizes recovery.');
    unlink($marker);
    $check(!admin_recovery_owner_valid($site,$secret),'Removal of the marker revokes recovery immediately.');
    file_put_contents($marker,'',LOCK_EX);
    unlink($key);
    $check(!admin_recovery_owner_valid($site,$secret),'Removal of the external owner key revokes recovery immediately.');
    $page=(string)file_get_contents($root.'/admin-password-reset.php');
    $service=(string)file_get_contents($root.'/app/admin-recovery.php');
    $check(str_contains($page,"\$_POST['setup_token']")&&str_contains($page,'admin_recovery_owner_valid('),
       'Recovery form requires typed owner proof and checks it under the advisory lock.');
    $check(str_contains($page,'require_csrf()')&&str_contains($page,'annotated_admin_password_reset'),
       'Existing CSRF and serial recovery advisory lock preserved.');
    $check(str_contains($page,'admin_recovery_apply(')&&!str_contains($page,'UPDATE users SET password_hash=? WHERE'),
       'No weaker password-only SQL fallback in the HTTP recovery handler.');
    $check(str_contains($service,'$pdo->beginTransaction()')
        &&str_contains($service,'$pdo->rollBack()')
        &&str_contains($service,'UPDATE extension_sessions SET revoked_at=NOW()')
        &&str_contains($service,'sessions_revoked_before=DATE_ADD(NOW(), INTERVAL 1 SECOND)'),
        'Browser and extension revocation must be atomic and reject same-second old sessions.');
    $check(str_contains($page,'unlink(installer_setup_token_path(')
       &&str_contains($page,'unlink($marker)'), 'Recovery consumes both operator enabling files.');
    $check(!is_file($root.'/database/migrations/20261001_130_admin_recovery.sql'),
       'Recovery adds no duplicate identity or new migration.');
}finally{if(is_file($key)||is_link($key))@unlink($key);if(is_file($marker))@unlink($marker);@rmdir($site);@rmdir($tmp);}
if($failed){foreach($failed as $m)fwrite(STDERR,'FAIL: '.$m.PHP_EOL);exit(1);}
echo "V1 admin recovery proof, session revocation and atomicity contracts passed.\n";
