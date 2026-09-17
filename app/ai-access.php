<?php
declare(strict_types=1);

function ai_interactive_model_record(PDO $pdo,array $user,int $modelId): array {
    $model=ai_model_record($pdo,$modelId);
    if(($user['role']??'')==='admin'){
        if(!(int)$model['admin_enabled'])throw new RuntimeException('This AI model is not enabled for administrators.');
        return $model;
    }
    if(!user_is_pro($pdo,$user))throw new RuntimeException('This AI feature is available to Pro users.');
    if(!(int)$model['pro_enabled'])throw new RuntimeException('This AI model is not enabled for Pro users.');
    return $model;
}
