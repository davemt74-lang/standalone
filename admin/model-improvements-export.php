<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$u=require_admin($pdo);
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('POST required.');}
require_csrf();$public=trim((string)($_POST['case']??''));$case=data_model_improvement_case_get($pdo,$public);if(!$case){http_response_code(404);exit('Improvement case not found.');}
$proposals=data_model_improvement_proposals($pdo,(int)$case['id']);foreach($proposals as &$p){unset($p['id'],$p['case_id'],$p['created_by_user_id'],$p['approved_by_user_id']);}unset($p);
$payload=['schema'=>'annotated.model-improvement-case.v1','exported_at'=>gmdate('c'),'exported_by_user_id'=>(int)$u['id'],'privacy_boundary'=>'Evidence references and human-sanitized proposals only; raw production prompts and model output are not exported.','case'=>$case,'evidence'=>data_model_improvement_evidence($pdo,(int)$case['id']),'proposals'=>$proposals,'events'=>data_model_improvement_events($pdo,(int)$case['id'],500)];
header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="annotated-model-improvement-'.preg_replace('/[^A-Za-z0-9_-]/','',$public).'.json"');echo json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
