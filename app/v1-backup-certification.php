<?php
declare(strict_types=1);
require_once __DIR__.'/release-operations.php';

/**
 * V1 additive certification of the two existing backup components.
 * SHA-256 detects corruption, not maliciously re-signed backups: accept
 * restore material only from a trusted operator-held storage location.
 */
function v1_backup_archive_entries_validate(array $names,array $verbose,string $expectedRoot): array {
    $errors=[];
    if($expectedRoot===''||in_array($expectedRoot,['.','..'],true)
       ||str_contains($expectedRoot,'/')||str_contains($expectedRoot,chr(92))
       ||preg_match('/[\\x00-\\x1f\\x7f]/',$expectedRoot))return ['Unsafe configured private-storage basename.'];
    if(!$names)$errors[]='Private storage archive is empty.';
    if(count($names)!==count($verbose))$errors[]='Tar detailed and path listings disagree.';
    $foundRoot=false;
    foreach($names as $i=>$entry){
        $entry=rtrim((string)$entry,chr(13));
        if($entry===''||preg_match('/[\\x00-\\x1f\\x7f]/',$entry)){$errors[]='Unsafe or ambiguous tar entry name.';continue;}
        if(str_starts_with($entry,'/')||str_starts_with($entry,'./')
           ||preg_match('~(^|/)\\.\\.?(/|$)~',$entry)
           ||str_contains($entry,'\\\\')||str_contains($entry,'//')){
            $errors[]='Absolute, ambiguous or traversal tar entry detected.';continue;
        }
        $parts=explode('/',rtrim($entry,'/'));
        if(($parts[0]??'')!==$expectedRoot){$errors[]='Tar entry escapes the configured private-storage root.';continue;}
        if(count($parts)===1)$foundRoot=true;
        $detail=(string)($verbose[$i]??'');
        // GNU and BSD tar begin verbose lines with a type/mode character.
        // Refuse links, devices and other non-regular objects; even a
        // correctly rooted symlink could point outside after extraction.
        $type=substr($detail,0,1);
        if(!in_array($type,['-','d'],true))$errors[]='Backup contains a link or special archive object.';
        if($type==='-'&&str_ends_with($entry,'/'))$errors[]='Regular-file archive entry has a directory path.';
        if($type==='d'&&!str_ends_with($entry,'/'))$errors[]='Directory archive entry has an ambiguous path.';
    }
    if(!$foundRoot)$errors[]='Configured storage root entry is missing.';
    return array_values(array_unique($errors));
}
function v1_backup_archive_certify(string $backupDir,string $expectedStorageRoot,?string $tar=null,?string $gzip=null,bool $writerInProgress=false): array {
    $verified=release_backup_manifest_verify($backupDir,$writerInProgress);
    if(!$verified['ok'])return ['ready'=>false,'errors'=>$verified['errors'],'backup_id'=>null];
    $errors=[];$backupDir=rtrim($backupDir,'/');
    $manifest=$verified['manifest'];
    if((string)($manifest['private_storage_basename']??'')!==$expectedStorageRoot)
        $errors[]='Backup storage root does not match the requested target.';
    foreach(['manifest.json','database.sql.gz','private-storage.tar.gz'] as $name){
        $path=$backupDir.'/'.$name;
        if(is_link($path)||!is_file($path))$errors[]='Backup component is missing or is a symlink: '.$name;
    }
    if(!$errors){
        $gzip=$gzip?:release_command_path(['gzip']);
        $tar=$tar?:release_command_path(['tar']);
        if($gzip===null||$tar===null)$errors[]='gzip and tar are required to certify archive contents.';
        else{
            $test=release_process_run([$gzip,'-t',$backupDir.'/database.sql.gz']);
            if($test['code']!==0)$errors[]='Database gzip CRC or stream integrity failed.';
            // -P prevents tar from silently stripping absolute path prefixes.
            $archive=$backupDir.'/private-storage.tar.gz';
            $listed=release_process_run([$tar,'-P','--quoting-style=escape','-tzf',$archive]);
            $detailed=release_process_run([$tar,'-P','--quoting-style=escape','-tvzf',$archive]);
            if($listed['code']!==0||$detailed['code']!==0)$errors[]='Private storage archive failed full tar listing/integrity validation.';
            else{
                $names=explode("\n",rtrim($listed['stdout'],"\n"));
                $verbose=explode("\n",rtrim($detailed['stdout'],"\n"));
                $errors=array_merge($errors,v1_backup_archive_entries_validate($names,$verbose,$expectedStorageRoot));
            }
        }
    }
    return [
      'ready'=>!$errors,
      'errors'=>array_values(array_unique($errors)),
      'backup_id'=>(string)($manifest['backup_id']??''),
      'storage_root'=>$expectedStorageRoot,
      'verified_at'=>gmdate('c'),
      'production_restored'=>false,
      'integrity_is_authentication'=>false,
    ];
}
function v1_backup_recovery_evidence(array $result): array {
    return [
      'archive_certified'=>!empty($result['ready']),
      'destructive_restore_executed'=>false,
      'production_restored'=>false,
      'human_restore_confirmation_required'=>true,
      'errors'=>$result['errors']??[],
    ];
}
