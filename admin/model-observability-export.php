<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$u=require_admin($pdo);
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('POST required.');}
require_csrf();$public=trim((string)($_POST['deployment']??''));$q=$pdo->prepare('SELECT id FROM data_model_deployments WHERE public_id=? LIMIT 1');$q->execute([$public]);$id=(int)($q->fetchColumn()?:0);if(!$id){http_response_code(404);exit('Deployment not found.');}
$deployment=data_model_observability_deployment_row($pdo,$id);$policy=data_model_observability_policy_get($pdo,$id,true);$snapshots=data_model_observability_snapshots($pdo,$id,500);$observations=data_model_observability_observations($pdo,$id,500);$iq=$pdo->prepare('SELECT * FROM data_model_incidents WHERE deployment_id=? ORDER BY id DESC LIMIT 500');$iq->execute([$id]);$incidents=$iq->fetchAll();$events=[];foreach($incidents as $i){$q=$pdo->prepare('SELECT event_type,from_status,to_status,note,evidence_hash,created_at FROM data_model_incident_events WHERE incident_id=? ORDER BY id');$q->execute([$i['id']]);$events[$i['public_id']]=$q->fetchAll();}
foreach($observations as &$o){unset($o['id'],$o['ai_run_id']);}unset($o);
$payload=['schema'=>'annotated.model-observability.v1','exported_at'=>gmdate('c'),'exported_by_user_id'=>(int)$u['id'],'boundary'=>'Telemetry/outcomes only; no prompts or model output text.','deployment'=>$deployment,'policy'=>$policy,'snapshots'=>$snapshots,'incidents'=>$incidents,'incident_events'=>$events,'observations'=>$observations];
header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="annotated-model-health-'.preg_replace('/[^A-Za-z0-9_-]/','',$public).'.json"');echo json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
