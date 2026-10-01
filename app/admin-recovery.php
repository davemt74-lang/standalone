<?php
declare(strict_types=1);
require_once __DIR__.'/installer.php';

/** Recovery needs both an explicit operator marker and a separate 256-bit owner secret. */
function admin_recovery_enabled(string $siteRoot): bool {
    $marker=rtrim($siteRoot,'/\\').'/admin-password-reset.enable';
    return !is_link($marker)&&is_file($marker)&&installer_setup_token_read($siteRoot)!==null;
}
function admin_recovery_owner_valid(string $siteRoot,string $token): bool {
    return admin_recovery_enabled($siteRoot)&&installer_setup_token_valid($siteRoot,$token);
}
/** No silent fallbacks: a reset must revoke both browser and extension sessions. */
function admin_recovery_apply(PDO $pdo,int $userId,string $newPassword): void {
    if($userId<=0||strlen($newPassword)<12)throw new InvalidArgumentException('Recovery user and password are invalid.');
    if(!migration_column_exists($pdo,'users','sessions_revoked_before')
       ||!installer_table_exists($pdo,'extension_sessions')
       ||!migration_column_exists($pdo,'extension_sessions','revoked_at'))
        throw new RuntimeException('Upgrade the database before administrator recovery: required session-revocation fields are missing.');
    $hash=password_hash($newPassword,PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    try{
        // current_user() rejects sessions with auth_time < revoked_before; one second
        // prevents a same-second existing browser session surviving the reset.
        $q=$pdo->prepare("UPDATE users SET password_hash=?,sessions_revoked_before=DATE_ADD(NOW(), INTERVAL 1 SECOND) WHERE id=? AND role='admin' AND status='active'");
        $q->execute([$hash,$userId]);
        if($q->rowCount()!==1)throw new RuntimeException('Active administrator record is unavailable.');
        $pdo->prepare('UPDATE extension_sessions SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL')->execute([$userId]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
