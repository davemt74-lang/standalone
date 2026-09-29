<?php
declare(strict_types=1);

function team_create(PDO $pdo,array $viewer,string $name): array {
    $name=mb_substr(trim($name),0,190);
    if($name==='')throw new InvalidArgumentException('Team name is required.');
    $public=ulid_like();
    $pdo->beginTransaction();
    try{
        $pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$public,(int)$viewer['id'],$name]);
        $teamId=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner')")->execute([$teamId,(int)$viewer['id']]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
    return ['id'=>$teamId,'public_id'=>$public,'owner_user_id'=>(int)$viewer['id'],'name'=>$name];
}
