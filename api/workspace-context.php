<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/workspace-context.php';
api_headers();
$viewer=require_api_user($pdo);
$candidate=[
  'team_public_id'=>$_GET['team']??'',
  'research_public_id'=>$_GET['research']??'',
  'object_type'=>$_GET['object_type']??'',
  'object_public_id'=>$_GET['object']??'',
  'agent_conversation_public_id'=>$_GET['agent']??'',
];
json_response(['ok'=>true,'data'=>workspace_context_resolve($pdo,$viewer,$candidate)]);
