<?php
declare(strict_types=1);

function evidence_annotation_asset(PDO $pdo,string $annotationPublicId,?array $viewer,string $asset): ?string {
    $access=annotation_access($pdo,$annotationPublicId,$viewer);if(!$access)return null;
    $q=$pdo->prepare('SELECT a.audio_commentary_path,c.screenshot_target_path,c.screenshot_context_path,sv.screenshot_path source_snapshot,md.storage_path media_path FROM annotations a JOIN captures c ON c.id=a.capture_id JOIN source_versions sv ON sv.id=a.source_version_id LEFT JOIN media_derivatives md ON md.capture_id=c.id WHERE a.id=? LIMIT 1');
    $q->execute([$access['id']]);$r=$q->fetch();if(!$r)return null;
    $stored=match($asset){
        'target'=>(string)($r['screenshot_target_path']??''),
        'context'=>(string)($r['screenshot_context_path']??''),
        'audio'=>(string)($r['audio_commentary_path']??''),
        'media'=>(string)($r['media_path']??''),
        'source_snapshot'=>(string)($r['source_snapshot']??''),
        default=>'',
    };
    return $stored!==''?$stored:null;
}

function evidence_source_snapshot(PDO $pdo,string $sourcePublicId,int $versionId,?array $viewer): ?string {
    $source=source_access($pdo,$sourcePublicId,$viewer);if(!$source)return null;
    $q=$pdo->prepare('SELECT screenshot_path FROM source_versions WHERE source_id=? AND id=?');
    $q->execute([$source['id'],$versionId]);$stored=(string)($q->fetchColumn()?:'');
    return $stored!==''?$stored:null;
}
