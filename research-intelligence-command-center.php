<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);
header('Cache-Control: private, no-store');
header('Vary: Cookie');
$portfolio=trim((string)($_GET['portfolio']??''));
header('Location: '.research_portfolios_href('overview',$portfolio),true,302);
exit;
