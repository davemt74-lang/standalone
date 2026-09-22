<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$u=require_admin($pdo);
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('POST required.');}
require_csrf();$public=trim((string)($_POST['campaign']??''));$campaign=data_model_campaign_get($pdo,$public,true);if(!$campaign){http_response_code(404);exit('Campaign not found.');}
$payload=['schema'=>'annotated.model-improvement-campaign.v1','exported_at'=>gmdate('c'),'exported_by_user_id'=>(int)$u['id'],'boundary'=>'Campaign scope, hashes, governed handoffs, and sanitized evidence references only. No raw production prompts or model output. No secrets.','campaign'=>$campaign,'plan_integrity'=>$campaign['plan_hash']?data_model_campaign_plan_integrity($pdo,$campaign):null,'current_use'=>$campaign['plan_hash']?data_model_campaign_current_use($pdo,$campaign):null,'cases'=>data_model_campaign_cases($pdo,(int)$campaign['id']),'proposals'=>data_model_campaign_proposals($pdo,(int)$campaign['id']),'outcome'=>data_model_campaign_outcome($pdo,$campaign),'events'=>data_model_campaign_events($pdo,(int)$campaign['id'],500)];
header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="annotated-model-campaign-'.preg_replace('/[^A-Za-z0-9_-]/','',$public).'.json"');echo json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
