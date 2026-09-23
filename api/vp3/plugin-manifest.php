<?php
declare(strict_types=1);
$GLOBALS['annotated_shell_disabled']=true;
require dirname(__DIR__,2).'/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['ok'=>true,'data'=>vp3_host_manifest()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
