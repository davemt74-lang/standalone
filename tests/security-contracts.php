<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use($root,&$fail){$s=(string)file_get_contents($root.'/'.$file);if(!str_contains($s,$needle))$fail[]=$message;};

$need('app/bootstrap.php',"session.use_strict_mode",'Sessions must use strict mode.');
$need('app/bootstrap.php',"samesite'=>'Lax'",'Session cookies must explicitly set SameSite.');
$need('app/extension-auth.php','extension_redirect_allowed','Extension redirects must be allowlisted.');
$need('app/extension-auth.php','extension_allowed_ids','Extension CORS must be bound to configured extension IDs.');
$need('app/extension-auth.php','expires_at IS NULL OR expires_at>NOW()','Extension bearer sessions must enforce expiry.');
$need('api/extension.php','enforce_extension_bearer_session','The extension API must reject expired bearer sessions.');
$need('extension-authorize.php','extension_redirect_allowed','Extension authorization must validate the exact redirect.');
$need('api/extension-token.php','session_ttl_days','Extension tokens must receive a bounded lifetime.');
$need('first-admin.php','bootstrap_key','First-admin creation must require a private bootstrap key.');
$need('app/oauth.php','linkUserId','OAuth linking must use an explicit link target.');
$need('app/oauth.php','already connected to another Annotated account','OAuth provider collision must fail closed.');
$need('extension/content.js','includePageText=false','Page text must be opt-in from the content script.');
$need('extension/sidepanel-capture.js','readPage(true)','Full page text may be collected only for explicit publish.');
$state=(string)file_get_contents($root.'/extension/sidepanel-state.js');
if(preg_match("/action=page_context[^\n]+page_text/",$state))$fail[]='Passive page-context calls must not upload page text.';
$need('extension/sidepanel-state.js','normalizeApiBase','Extension API base must reject insecure remote origins.');
$need('config.example.php','allowed_ids','Config must expose the exact Chrome extension allowlist.');
$need('config.example.php','bootstrap_key','Config must expose the one-time first-admin bootstrap key.');
if(!is_file($root.'/database/migrations/20260917_005_auth_extension_hardening.sql'))$fail[]='Auth/extension hardening migration 005 is missing.';
if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Security contracts passed.\n";
