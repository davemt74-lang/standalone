<?php
declare(strict_types=1);require __DIR__.'/app/bootstrap.php';require_user($pdo);$provider=(string)($_GET['provider']??'');if(!in_array($provider,['google','x'],true)){http_response_code(400);exit('Invalid provider.');}$_SESSION['after_login']='/connected-accounts.php';header('Location: /oauth/'.$provider.'.php');
