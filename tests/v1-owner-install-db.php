<?php
declare(strict_types=1);
/** Targeted real-DB gate; section CI provisions current schema before this journey. */
$root=dirname(__DIR__);
require_once $root.'/app/installer.php';
$dsn=(string)getenv('DB_DSN');
if($dsn==='')throw new RuntimeException('DB_DSN is required for V1 owner install DB gate.');
$pdo=new PDO($dsn,(string)getenv('DB_USER'),(string)getenv('DB_PASS'),[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false]);
$assert=static function(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label.PHP_EOL;};
$assert(installer_base_schema_ready($pdo,$root.'/database/schema.sql'),'Bundled base schema is present.');
$assert(installer_pending_migrations($pdo,$root.'/database/migrations')===[],'No pending migrations after current-schema preparation.');
$assert((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===0,'New install has no privileged user.');
$tmp=sys_get_temp_dir().'/owner-db-'.bin2hex(random_bytes(6));
$site=$tmp.'/public_html';
if(!mkdir($site,0700,true))throw new RuntimeException('Temporary setup fixture unavailable.');
$key=installer_setup_token_path($site);
$secret=bin2hex(random_bytes(32));
try{
    $assert(!installer_first_admin_claim_allowed($pdo,$site,$secret),'First-admin requires server-owner proof even when no users exist.');
    file_put_contents($key,$secret."\n",LOCK_EX);
    $assert(!installer_first_admin_claim_allowed($pdo,$site,str_repeat('0',64)),'Invalid owner proof cannot claim empty installation.');
    $assert(installer_first_admin_claim_allowed($pdo,$site,$secret),'Valid owner can claim first admin on current complete schema.');
    $uid='owner-db-'.bin2hex(random_bytes(5));
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,password_hash,role) VALUES(?,?,?,?,?,'admin')")
      ->execute([$uid,$uid,'Owner Certification',$uid.'@example.test',password_hash(bin2hex(random_bytes(18)),PASSWORD_DEFAULT)]);
    $assert(!installer_first_admin_claim_allowed($pdo,$site,$secret),'First-admin proof cannot claim a second admin after setup.');
    $assert(installer_pending_migrations($pdo,$root.'/database/migrations')===[],'First-admin creates no pending migration.');
    $pdo->prepare('DELETE FROM users WHERE public_id=?')->execute([$uid]);
    unlink($key);
    $assert(!installer_first_admin_claim_allowed($pdo,$site,$secret),'Removed setup token permanently closes first-admin claim.');
}finally{
    if(is_file($key))@unlink($key);
    @rmdir($site);@rmdir($tmp);
}
echo "V1 owner-first install DB journey passed.\n";
