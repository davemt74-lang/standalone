<?php
declare(strict_types=1);
$GLOBALS['annotated_shell_disabled']=true;
require dirname(__DIR__).'/app/bootstrap.php';
try{$token=trim((string)($_GET['token']??$_POST['token']??''));if($token==='')throw new RuntimeException('VP3 launch token is required.');$result=vp3_host_launch($pdo,$token);header('Cache-Control: no-store, private');header('Location: '.$result['destination']);exit;}
catch(Throwable $e){http_response_code(403);header('Cache-Control: no-store');?><!doctype html><meta charset="utf-8"><title>VP3 launch unavailable · Annotated</title><main style="max-width:620px;margin:10vh auto;font-family:system-ui;padding:24px"><h1>VP3 launch unavailable</h1><p><?=h($e->getMessage())?></p><p><a href="/login.php">Use Annotated sign in</a></p></main><?php }
