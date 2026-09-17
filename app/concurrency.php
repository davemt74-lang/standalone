<?php
declare(strict_types=1);

function lock_source_row(PDO $pdo,int $sourceId): array {
    if(!$pdo->inTransaction()) throw new RuntimeException('Source row locks require an active transaction.');
    $q=$pdo->prepare('SELECT id,public_id,title,canonical_url,current_version_id,status FROM sources WHERE id=? FOR UPDATE');
    $q->execute([$sourceId]);$source=$q->fetch();
    if(!$source)throw new RuntimeException('Source no longer exists.');
    return $source;
}

function source_current_version(PDO $pdo,array $source): ?array {
    $id=(int)($source['current_version_id']??0);if(!$id)return null;
    $q=$pdo->prepare('SELECT * FROM source_versions WHERE id=? AND source_id=?');$q->execute([$id,$source['id']]);return $q->fetch()?:null;
}

function next_source_version_number(PDO $pdo,int $sourceId): int {
    if(!$pdo->inTransaction()) throw new RuntimeException('Version allocation requires an active transaction.');
    $q=$pdo->prepare('SELECT COALESCE(MAX(version_number),0)+1 FROM source_versions WHERE source_id=?');$q->execute([$sourceId]);return (int)$q->fetchColumn();
}
