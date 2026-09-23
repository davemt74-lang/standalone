<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';require_once $root.'/app/migrations.php';
if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
$baseName=preg_replace('/[^A-Za-z0-9_]/','_',installer_validate_db_identifier((string)$m[1]));$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
$admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$baselines=[
 'rc1'=>'20260922_046_model_improvement_campaigns.sql',
 'programs'=>'20260923_056_research_programs_recurring_intelligence.sql',
 'portfolios'=>'20260923_058_research_intelligence_portfolios_executive_briefing.sql',
];
foreach($baselines as $label=>$cutoff){
    $db=substr($baseName.'_'.preg_replace('/[^a-z0-9_]/','',strtolower($label)),0,60);$quoted='`'.str_replace('`','``',$db).'`';
    if((string)getenv('PHASE62_RESET_DBS')==='1'){$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');}
    $targetDsn=preg_replace('/dbname=[^;]+/','dbname='.$db,$dsn,1);$pdo=new PDO($targetDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $tmp=sys_get_temp_dir().'/annotated-phase62-'.$label.'-'.bin2hex(random_bytes(5));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create '.$label.' migration fixture.');
    try{
        foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$cutoff)<=0)copy($file,$tmp.'/'.$base);}
        installer_run($pdo,$root.'/database/schema.sql',$tmp);
        $before=installer_pending_migrations($pdo,$root.'/database/migrations');if(!$before)throw new RuntimeException($label.' baseline unexpectedly has no forward migrations.');
        $applied=migration_apply_pending($pdo,$root.'/database/migrations',30);if(!$applied)throw new RuntimeException($label.' baseline did not apply forward migrations.');
        $pending=installer_pending_migrations($pdo,$root.'/database/migrations');if($pending)throw new RuntimeException($label.' baseline left pending migrations: '.implode(', ',$pending));
        $again=migration_apply_pending($pdo,$root.'/database/migrations',30);if($again)throw new RuntimeException($label.' upgrade is not repeat-safe; second pass applied: '.implode(', ',$again));
        if(!installer_table_exists($pdo,'research_intelligence_portfolio_cycles')||!installer_table_exists($pdo,'research_publication_workflows')||!installer_table_exists($pdo,'research_programs'))throw new RuntimeException($label.' upgraded database is missing V1.1 final tables.');
        $q=$pdo->query("SELECT filename,status,attempts FROM schema_migration_runs WHERE status='failed'");if($q->fetch())throw new RuntimeException($label.' upgrade retained a failed migration run.');
        echo "PASS: Phase 62 supported baseline $label ($cutoff) upgraded through migration 059 and a repeat pass was a no-op.\n";
    }finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
}
echo "Phase 62 supported baseline upgrade/recovery rehearsal passed.\n";
