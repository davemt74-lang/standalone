<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$u=require_admin($pdo);
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('POST required.');}
require_csrf();
$id=trim((string)($_POST['deployment']??''));$d=data_model_deployment_get($pdo,$id);if(!$d){http_response_code(404);exit('Deployment not found.');}
$decision=data_model_release_decision_get($pdo,(string)$d['release_decision_public_id']);if(!$decision||!data_model_release_decision_integrity($pdo,$decision)['ok']){http_response_code(409);exit('Release decision integrity failed.');}
$checkpoints=[];foreach(data_model_deployment_checkpoints($pdo,(int)$d['id']) as $c){$c['integrity']=data_model_deployment_checkpoint_integrity($pdo,$d,$c);$checkpoints[]=$c;}
$payload=['schema'=>'annotated.model-deployment.v1','exported_at'=>gmdate('c'),'exported_by_user_id'=>(int)$u['id'],'deployment'=>$d,'release_decision_integrity'=>data_model_release_decision_integrity($pdo,$decision),'checkpoints'=>$checkpoints,'events'=>data_model_deployment_events($pdo,(int)$d['id'],500)];
header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="annotated-model-deployment-'.preg_replace('/[^A-Za-z0-9_-]/','',$d['public_id']).'.json"');echo json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
