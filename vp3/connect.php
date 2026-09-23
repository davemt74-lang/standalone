<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$mode=(string)($_GET['mode']??'connect');$viewer=current_user($pdo);
try{
    if($mode!=='login'&&!$viewer){$_SESSION['after_login']='/vp3/connect.php?mode=connect';header('Location: /login.php');exit;}
    $url=vp3_connector_begin($config,$mode,(int)($viewer['id']??0));header('Cache-Control: no-store');header('Location: '.$url);exit;
}catch(Throwable $e){http_response_code(503);?><!doctype html><meta charset="utf-8"><title>VP3 connection unavailable · Annotated</title><link rel="stylesheet" href="/assets/css/app.css"><main class="panel narrow"><h1>VP3 connection unavailable</h1><div class="error"><?=h($e->getMessage())?></div><p><a class="button secondary" href="<?=$viewer?'/settings.php':'/login.php'?>">Back</a></p></main><?php }
