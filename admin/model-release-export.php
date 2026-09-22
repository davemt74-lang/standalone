<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$u=require_admin($pdo);
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit('POST required.');}
require_csrf();
$id=trim((string)($_POST['decision_id']??''));
$decision=data_model_release_decision_get($pdo,$id);if(!$decision){http_response_code(404);exit('Release decision not found.');}
$integrity=data_model_release_decision_integrity($pdo,$decision);if(!$integrity['ok']){http_response_code(409);exit('Release decision integrity failed.');}
$packet=data_model_release_packet_by_id($pdo,(int)$decision['readiness_packet_id']);if(!$packet||!data_post_training_packet_integrity($packet)['ok']){http_response_code(409);exit('Readiness packet integrity failed.');}
$payload=[
  'schema'=>'annotated.model-release-decision.v1',
  'decision_public_id'=>$decision['public_id'],
  'decision_hash'=>$decision['decision_hash'],
  'final_signature_hash'=>$decision['final_signature_hash'],
  'status'=>$decision['status'],
  'outcome'=>$decision['outcome'],
  'title'=>$decision['title'],
  'decision_summary'=>$decision['decision_summary'],
  'rationale'=>$decision['rationale'],
  'final_signer'=>['user_id'=>(int)$decision['final_signed_by_user_id'],'display_name'=>$decision['final_signer_name'],'signed_at'=>$decision['final_signed_at']],
  'readiness_packet'=>['public_id'=>$decision['packet_public_id'],'hash'=>$decision['readiness_packet_hash']],
  'model'=>['public_id'=>$decision['model_version_public_id'],'version_label'=>$decision['model_version_label'],'status'=>$decision['model_status'],'version_hash'=>$decision['model_version_hash']],
  'baseline'=>['public_id'=>$decision['baseline_version_public_id'],'version_label'=>$decision['baseline_version_label'],'version_hash'=>$decision['baseline_version_hash']],
  'risk_policy'=>$decision['risk_policy'],
  'deployment_plan'=>$decision['deployment_plan'],
  'rollback_plan'=>$decision['rollback_plan'],
  'checklist'=>data_model_release_checklist($pdo,(int)$decision['id']),
  'reviews'=>data_model_release_reviews($pdo,(int)$decision['id']),
  'signatures'=>data_model_release_signatures($pdo,(int)$decision['id']),
  'integrity'=>$integrity,
  'boundary'=>'This signed record documents a human release decision. It does not itself change model lifecycle status or production AI routing.',
];
$json=json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="annotated-model-release-'.preg_replace('/[^a-zA-Z0-9_-]+/','-',strtolower($decision['public_id'])).'.json"');header('Cache-Control: no-store');echo $json;