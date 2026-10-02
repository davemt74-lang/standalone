<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/app/v1-backup-certification.php';
$failed=[];
$check=static function(bool $ok,string $m)use(&$failed):void{if(!$ok)$failed[]=$m;};
$valid=v1_backup_archive_entries_validate(
 ['private/','private/example.txt','private/sub/','private/sub/file.txt'],
 ['drwx------ 0/0 0 2026-01-01 00:00 private/','-rw------- 0/0 4 2026-01-01 00:00 private/example.txt','drwx------ 0/0 0 2026-01-01 00:00 private/sub/','-rw------- 0/0 4 2026-01-01 00:00 private/sub/file.txt'],
 'private'
);
$check($valid===[],'Properly rooted regular files and directories are accepted.');
$bad=[
 ['private/','private/../config.php'],
 ['private/','/etc/passwd'],
 ['private/','other/file.txt'],
 ['private/','private//duplicate.txt'],
 ['private/','private/./ambiguous.txt'],
 ['private/','private/file'."\n".'other/stealth'],
];
foreach($bad as $i=>$names){
 $verbose=['drwx------ 0/0 0 2026-01-01 00:00 private/','-rw------- 0/0 4 2026-01-01 00:00 unsafe'];
 $check(v1_backup_archive_entries_validate($names,$verbose,'private')!==[],'Unsafe archive entry denied: fixture '.$i);
}
$check(v1_backup_archive_entries_validate(['private/','private/link'],['drwx------ 0/0 0 2026-01-01 00:00 private/','lrwxrwxrwx 0/0 0 2026-01-01 00:00 private/link -> /etc'],'private')!==[],
    'Symlink to external data rejected.');
$check(v1_backup_archive_entries_validate(['private/','private/fifo'],['drwx------ 0/0 0 2026-01-01 00:00 private/','prw------- 0/0 0 2026-01-01 00:00 private/fifo'],'private')!==[],
    'Special objects rejected.');
$check(v1_backup_archive_entries_validate(['private/'],[],'private')!==[],
    'Mismatched tar listings rejected.');
$check(v1_backup_archive_entries_validate(['other/'],['drwx------ 0/0 0 2026-01-01 00:00 other/'],'private')!==[],
    'Missing storage root rejected.');
$check(v1_backup_archive_entries_validate(['private/'],['drwx------ 0/0 0 2026-01-01 00:00 private/'],'../')!==[],
    'Invalid target root rejected.');
$source=(string)file_get_contents($root.'/app/v1-backup-certification.php');
$cli=(string)file_get_contents($root.'/bin/v1-restore-certify.php');
$check(str_contains($source,"'-P'")&&str_contains($source,"'-tzf'")&&str_contains($source,"'-tvzf'"),
 'Tar is inspected with absolute-name retention and detailed entry metadata.');
$check(str_contains($source,"'-t',")&&str_contains($source,'database.sql.gz'),'Database gzip CRC check included.');
$check(str_contains($cli,'v1_backup_archive_certify(')&&str_contains($cli,'release_restore_plan('),
 'CLI certifies archive before publishing the existing manual restore plan.');
$check(str_contains($cli,"PHP_SAPI!=='cli'"),'Certification CLI is inaccessible by HTTP.');
$check(!is_file($root.'/database/migrations/20261001_130_v1_restore.sql'),
 'No new schema or destructive production restore subsystem.');
if($failed){foreach($failed as $m)fwrite(STDERR,'FAIL: '.$m.PHP_EOL);exit(1);}
echo "V1 backup archive safety and restore-certification contracts passed.\n";
