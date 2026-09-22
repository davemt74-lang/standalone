<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$u=require_admin($pdo);
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit('POST required.');}
require_csrf();
$planId=trim((string)($_POST['plan_id']??''));$packetId=trim((string)($_POST['packet_id']??''));
$plan=data_post_training_plan_get($pdo,$planId);if(!$plan){http_response_code(404);exit('Post-training plan not found.');}
$rows=data_post_training_packets($pdo,(int)$plan['id']);$packet=null;foreach($rows as $row)if($packetId===''||hash_equals((string)$row['public_id'],$packetId)){$packet=$row;break;}
if(!$packet){http_response_code(404);exit('Readiness packet not found.');}
$integrity=data_post_training_packet_integrity($packet);if(!$integrity['ok']){http_response_code(409);exit('Readiness packet integrity failed.');}
$payload=['schema'=>'annotated.post-training-readiness-export.v1','packet_public_id'=>$packet['public_id'],'packet_hash'=>$packet['packet_hash'],'created_at'=>$packet['created_at'],'packet'=>$packet['packet']];
$json=json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="annotated-readiness-'.preg_replace('/[^a-zA-Z0-9_-]+/','-',strtolower($plan['public_id'])).'.json"');header('Cache-Control: no-store');echo $json;