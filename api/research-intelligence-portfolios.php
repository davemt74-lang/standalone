<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';api_headers();
$action=(string)($_GET['action']??'list');$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    $read=['list','detail','dashboard'];$viewer=in_array($action,$read,true)?require_api_user($pdo):require_api_mutation_auth($pdo);
    if(!research_intelligence_portfolios_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Research Intelligence Portfolios require the latest database upgrade.']],503);
    if($action==='list')json_response(['ok'=>true,'data'=>['portfolios'=>research_intelligence_portfolio_list($pdo,$viewer,(int)($input['limit']??100),!empty($input['include_archived']))]]);
    if($action==='dashboard')json_response(['ok'=>true,'data'=>research_intelligence_portfolio_dashboard($pdo,$viewer)]);
    if($action==='detail'){$id=trim((string)($input['portfolio_id']??''));$p=research_intelligence_portfolio_detail($pdo,$viewer,$id);if(!$p)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);json_response(['ok'=>true,'data'=>['portfolio'=>$p]]);}
    rate_limit_api_or_429($pdo,'research-intelligence-portfolios-write','user:'.$viewer['id'],180,3600);
    if($action==='create')json_response(['ok'=>true,'data'=>['portfolio'=>research_intelligence_portfolio_create($pdo,$viewer,$input)]],201);
    if($action==='update')json_response(['ok'=>true,'data'=>['portfolio'=>research_intelligence_portfolio_update($pdo,$viewer,(string)($input['portfolio_id']??''),$input)]]);
    if($action==='add_program')json_response(['ok'=>true,'data'=>['portfolio'=>research_intelligence_portfolio_add_program($pdo,$viewer,(string)($input['portfolio_id']??''),(string)($input['program_id']??''),(string)($input['member_role']??'supporting'))]]);
    if($action==='remove_program')json_response(['ok'=>true,'data'=>['portfolio'=>research_intelligence_portfolio_remove_program($pdo,$viewer,(string)($input['portfolio_id']??''),(string)($input['program_id']??''))]]);
    if($action==='snapshot')json_response(['ok'=>true,'data'=>['snapshot'=>research_intelligence_portfolio_snapshot($pdo,$viewer,(string)($input['portfolio_id']??''),(int)($input['window_days']??30),'manual')]],201);
    if($action==='create_briefing')json_response(['ok'=>true,'data'=>['briefing'=>research_intelligence_portfolio_create_briefing($pdo,$viewer,(string)($input['portfolio_id']??''),$input)]],201);
    if($action==='prepare_publication')json_response(['ok'=>true,'data'=>['workflow'=>research_intelligence_portfolio_prepare_publication($pdo,$viewer,(string)($input['briefing_id']??''),$input)]]);
    if($action==='add_inference')json_response(['ok'=>true,'data'=>['insight'=>research_intelligence_portfolio_add_inference($pdo,$viewer,(string)($input['portfolio_id']??''),$input)]],201);
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'PORTFOLIO_ERROR','message'=>$e->getMessage()]],403);}
catch(Throwable $e){$ref=substr(hash('sha256','phase60|'.$e->getMessage().'|'.microtime(true)),0,12);error_log('[Annotated Phase 60 '.$ref.'] '.$e->getMessage());json_response(['ok'=>false,'error'=>['code'=>'PORTFOLIO_INTERNAL','message'=>'Portfolio intelligence could not complete this request. Reference: '.$ref]],500);}
