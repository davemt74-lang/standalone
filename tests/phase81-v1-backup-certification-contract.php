<?php
declare(strict_types=1);
/** V1 archive safety: independent, database-free negative-path checks. */
$root=dirname(__DIR__);
require_once $root.'/app/v1-backup-certification.php';
$errors=[];
$check=static function(bool $ok,string $label)use(&$errors):void{if(!$ok)$errors[]=$label;};
$ok=v1_backup_archive_entries_validate(
 ['private-store/','private-store/notes.txt'],
 ['drwx------ 0/0 0 Jan 1 00:00 private-store/','-rw------- 0/0 4 Jan 1 00:00 private-store/notes.txt'],'private-store');
$check($ok===[],'Contained regular files and directories are accepted.');
$cases=[
 ['private-store/','private-store/../../escape.txt'],
 ['private-store/','/tmp/exfiltrate'],
 ['private-store/','../private-store/escape'],
 ['private-store/','./private-store/file'],
 ['private-store/','different-root/private.txt'],
 ['private-store/','private-store//ambiguous'],
 ['private-store/','private-store/./ambiguous'],
 ['private-store/','private-store/back\\slash'],
];
foreach($cases as $i=>$entries){
    $verbose=['drwx------ 0/0 0 Jan 1 00:00 private-store/','-rw------- 0/0 4 Jan 1 00:00 payload'];
    $check(v1_backup_archive_entries_validate($entries,$verbose,'private-store')!==[],
        'Disallowed path form '.$i.' must not enter a restore archive.');
}
$check(v1_backup_archive_entries_validate(
 ['private-store/','private-store/leak'],
 ['drwx------ 0/0 0 Jan 1 00:00 private-store/','lrwxrwxrwx 0/0 0 Jan 1 00:00 private-store/leak -> /etc'],'private-store')!==[],
 'A symlink inside the root cannot escape the restore destination.');
$check(v1_backup_archive_entries_validate(
 ['private-store/','private-store/hardlink'],
 ['drwx------ 0/0 0 Jan 1 00:00 private-store/','hrw------- 0/0 0 Jan 1 00:00 private-store/hardlink'],'private-store')!==[],
 'Hard links and special file types are refused.');
$check(v1_backup_archive_entries_validate(['other/file'],['-rw------- 0/0 0 Jan 1 00:00 other/file'],'private-store')!==[],
 'Missing matching storage root blocks restore.');
$cert=(string)file_get_contents($root.'/app/v1-backup-certification.php');
$cli=(string)file_get_contents($root.'/bin/v1-restore-certify.php');
$check(str_contains($cert,'release_backup_manifest_verify(')&&str_contains($cert,"'-t'"),
 'Canonical manifest verification and gzip stream test are mandatory.');
$check(str_contains($cert,"'-P'")&&str_contains($cert,"'-tvzf'"),
 'Archive names and detailed file types are inspected without stripping absolute paths.');
$check(str_contains($cli,'release_restore_plan(')&&!str_contains($cli,'DROP DATABASE')
 &&!str_contains($cli,'proc_open('),'CLI produces an existing guarded restore plan without executing recovery.');
$check(str_contains($cert,"'production_restored'=>false")
 &&str_contains($cert,"'integrity_is_authentication'=>false"),'Archive integrity never claims actual restoration or provenance authentication.');
if($errors){foreach($errors as $e)fwrite(STDERR,"FAIL: $e\n");exit(1);}
echo "V1 backup/restore archive safety contract passed.\n";
