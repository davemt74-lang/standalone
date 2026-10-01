<?php
declare(strict_types=1);
/** V1 owner-authorized install/first-admin/upgrade; zero DB required. */
$root=dirname(__DIR__);
require_once $root.'/app/installer.php';
$failed=[];
$check=static function(bool $ok,string $message)use(&$failed):void{if(!$ok)$failed[]=$message;};
$fixture=sys_get_temp_dir().'/annotated-owner-contract-'.bin2hex(random_bytes(6));
$site=$fixture.'/public_html';
if(!mkdir($site,0700,true))throw new RuntimeException('Owner fixture directory unavailable.');
$key=installer_setup_token_path($site);
$secret=bin2hex(random_bytes(32));
try{
    $session=[];
    $check(dirname($key)===$fixture,'Token is outside webroot, in parent directory.');
    $check(!installer_setup_token_valid($site,$secret),'Missing owner key denies installer authorization.');
    $check(!installer_setup_session_ready($site,$session),'Unverified browser session is denied.');
    file_put_contents($key,$secret."\n",LOCK_EX);
    chmod($key,0600);
    $check(!installer_setup_token_valid($site,str_repeat('a',64)),'Wrong but well-formed token fails.');
    $check(!installer_setup_token_valid($site,'INVALID'),'Low-entropy and malformed tokens fail.');
    $check(installer_setup_token_valid($site,$secret),'Valid 32-byte owner proof succeeds.');
    $check(installer_setup_session_authorize($site,$secret,$session),'Owner proof authorizes a single server-side install session.');
    $check(installer_setup_session_ready($site,$session),'Verified current token validates install session.');
    file_put_contents($key,bin2hex(random_bytes(32))."\n",LOCK_EX);
    $check(!installer_setup_session_ready($site,$session),'Rotating owner key invalidates an old install session.');
    unlink($key);
    $check(!installer_setup_session_ready($site,$session),'Deleting owner key invalidates an authorized browser session.');
    $check(!installer_setup_token_valid($site,$secret),'Deleted owner proof cannot be reused.');
    if(function_exists('symlink')){
        @symlink($fixture.'/not-real',$key);
        if(is_link($key))$check(!installer_setup_token_valid($site,$secret),'Symlink owner proof rejected.');
        if(is_link($key))unlink($key);
    }
    $install=(string)file_get_contents($root.'/install.php');
    $first=(string)file_get_contents($root.'/first-admin.php');
    $upgrade=(string)file_get_contents($root.'/upgrade.php');
    $cli=(string)file_get_contents($root.'/bin/create-setup-token.php');
    $check(str_contains($install,"\$action==='authorize'")&&str_contains($install,'installer_setup_session_authorize(')
      &&str_contains($install,'elseif(!$ownerAuthorized)'),'Installer changes require a verified owner session.');
    $check(str_contains($first,'installer_first_admin_claim_allowed(')
      &&str_contains($first,"\$_POST['setup_token']"),'First-admin creation independently rechecks owner proof under a DB lock.');
    $check(str_contains($first,'unlink(installer_setup_token_path('),'Successful first-admin clears setup key.');
    $check(str_contains($upgrade,'if(!users_exist($pdo))')&&str_contains($upgrade,'Administrator access required'),
      'Web upgrade denies empty-site requests and demands an authenticated admin.');
    $check(str_contains($cli,"PHP_SAPI!=='cli'")&&str_contains($cli,"fopen(\$file,'x')"),
      'Setup-token generator is CLI-only and cannot overwrite an existing proof.');
    $check(!is_file($root.'/database/migrations/20261001_130_v1_owner_install.sql'),
      'Installer authorization adds no duplicate schema or migration.');
}finally{
    if(is_link($key)||is_file($key))@unlink($key);
    @rmdir($site);@rmdir($fixture);
}
if($failed){foreach($failed as $error)fwrite(STDERR,'FAIL: '.$error.PHP_EOL);exit(1);}
echo "V1 owner-authorized installer, first-admin and upgrade static contract passed.\n";
