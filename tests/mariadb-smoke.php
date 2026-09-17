<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$user=(string)getenv('DB_USER');$pass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function sql_statements(string $sql): array {$out=[];$buf='';foreach(preg_split('/\R/',$sql) as $line){$trim=trim($line);if($trim===''||str_starts_with($trim,'--'))continue;$buf.=$line."\n";if(str_ends_with(rtrim($line),';')){$out[]=trim($buf);$buf='';}}if(trim($buf)!=='')$out[]=trim($buf);return $out;}
foreach(sql_statements((string)file_get_contents($root.'/database/schema.sql')) as $sql)$pdo->exec($sql);
$files=glob($root.'/database/migrations/*.sql')?:[];sort($files,SORT_STRING);foreach($files as $file)foreach(sql_statements((string)file_get_contents($file)) as $sql)$pdo->exec($sql);
$tables=['users','annotations','ai_models','source_monitor_jobs','rate_limit_buckets'];foreach($tables as $table){$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);if((int)$q->fetchColumn()!==1)throw new RuntimeException("Missing table: $table");}
require_once $root.'/app/rate-limit.php';
$subject='ci-'.bin2hex(random_bytes(8));$a=rate_limit_consume($pdo,'ci-smoke',$subject,2,3600);$b=rate_limit_consume($pdo,'ci-smoke',$subject,2,3600);$c=rate_limit_consume($pdo,'ci-smoke',$subject,2,3600);if(!$a['allowed']||!$b['allowed']||$c['allowed'])throw new RuntimeException('Rate limiter did not enforce the configured threshold.');
$q=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='media_jobs' AND column_name='claim_token'");if((int)$q->fetchColumn()!==1)throw new RuntimeException('Worker lease migration was not applied.');
echo 'MariaDB schema, migrations, and rate limiting smoke test passed ('.count($files)." migrations).\n";
