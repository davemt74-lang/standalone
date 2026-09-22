<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);
require_once $root.'/app/release.php';
require_once $root.'/app/release-operations.php';
$sha=(string)(getenv('GITHUB_SHA')?:getenv('ANNOTATED_BUILD_SHA')?:'');
echo json_encode(release_manifest_data($root,$sha!==''?$sha:null),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
