<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use($root,&$fail){$s=(string)file_get_contents($root.'/'.$file);if(!str_contains($s,$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use($root,&$fail){$s=(string)file_get_contents($root.'/'.$file);if(str_contains($s,$needle))$fail[]=$message;};

$need('app/bootstrap.php',"session.use_strict_mode",'Sessions must use strict mode.');
$need('app/bootstrap.php',"samesite'=>'Lax'",'Session cookies must explicitly set SameSite.');
$need('app/extension-auth.php','extension_redirect_allowed','Extension redirects must be allowlisted.');
$need('app/extension-auth.php','extension_allowed_ids','Extension CORS must be bound to configured extension IDs.');
$need('app/extension-auth.php','expires_at IS NULL OR expires_at>NOW()','Extension bearer sessions must enforce expiry.');
$need('app/functions.php','expires_at IS NULL OR expires_at>NOW()','Shared bearer authentication must reject expired extension sessions.');
$need('api/extension.php','enforce_extension_bearer_session','The extension API must reject expired bearer sessions.');
$need('extension-authorize.php','extension_redirect_allowed','Extension authorization must validate the exact redirect.');
$need('api/extension-token.php','session_ttl_days','Extension tokens must receive a bounded lifetime.');
$need('first-admin.php','bootstrap_key','First-admin creation must require a private bootstrap key.');
$need('app/oauth.php','linkUserId','OAuth linking must use an explicit link target.');
$need('app/oauth.php','already connected to another Annotated account','OAuth provider collision must fail closed.');
$need('extension/content.js','includePageText=false','Page text must be opt-in from the content script.');
$need('extension/sidepanel-capture.js','readPage(true)','Full page text may be collected only for explicit publish.');
$state=(string)file_get_contents($root.'/extension/sidepanel-state.js');
if(preg_match("/action=page_context[^\\n]+page_text/",$state))$fail[]='Passive page-context calls must not upload page text.';
$need('extension/sidepanel-state.js','normalizeApiBase','Extension API base must reject insecure remote origins.');
$need('config.example.php','allowed_ids','Config must expose the exact Chrome extension allowlist.');
$need('config.example.php','bootstrap_key','Config must expose the one-time first-admin bootstrap key.');

$need('app/access.php','function annotation_access','Annotation visibility must be centralized.');
$need('app/access.php','function source_access','Source visibility must be centralized.');
$need('app/access.php','function presence_identity_visible','Presence identity disclosure must be centralized.');
$need('app/access.php',"if(\$mode==='team_only')return users_share_team",'Team-only presence must disclose identity only to shared-team viewers.');
$need('api/extension-base.php','ext_annotation($pdo','Extension annotation mutations must use viewer-scoped access.');
$need('api/extension-live.php','project_can_write($projectRow)','Research writes must reject viewer-only project access.');
$need('api/extension-live.php','presence_identity_visible','Live presence must use the centralized identity-disclosure policy.');
$need('source.php','source_access($pdo,$id,$u)','Source pages must enforce source visibility.');
$need('source-compare.php','source_access($pdo,$id,$viewer)','Source comparison must enforce source visibility.');
$need('search.php','EXISTS(SELECT 1 FROM annotations pa','Search must expose only publicly discoverable sources.');
$need('annotation.php','annotation_access($pdo,$id,$viewer)','Direct annotation pages must enforce centralized visibility.');
if(!is_file($root.'/database/migrations/20260917_005_auth_extension_hardening.sql'))$fail[]='Auth/extension hardening migration 005 is missing.';

$need('app/storage.php','private_storage_root','Private evidence storage must have a dedicated filesystem root.');
$need('app/evidence-access.php','annotation_access($pdo,$annotationPublicId,$viewer)','Annotation evidence delivery must re-check authorization.');
$need('app/evidence-access.php','source_access($pdo,$sourcePublicId,$viewer)','Source evidence delivery must re-check authorization.');
$need('evidence.php','evidence_annotation_asset','Evidence gateway must use the centralized annotation evidence resolver.');
$need('evidence.php','evidence_source_snapshot','Evidence gateway must use the centralized Source evidence resolver.');
$need('worker/media-worker.php','private_storage_allocate','Media derivatives must be written to private storage.');
$need('worker/transcription-worker.php','storage_path_to_absolute','Transcription must resolve private storage references.');
$need('api/extension-publish.php',"save_data_url_audio((string)(\$input['audio_commentary']??''),\$config)",'Published audio must use private storage.');
$need('saved.php','annotation_access($pdo,$annotation,$u)','Adding to collections must re-check annotation access.');
$need('saved.php','array_filter($q->fetchAll(),fn($row)=>annotation_access','Saved/collection owner views must revalidate current access.');
$need('collection.php',"\$scope=\$c['visibility']==='public'?null:\$viewer",'Public collections must not inherit the owner private access scope.');
$need('bin/migrate-private-evidence.php','/storage/uploads/','A legacy evidence migration utility is required.');
$need('storage/.htaccess','Require all denied','Legacy storage URLs must be denied on Apache.');
$directAudio="src=\"<?=h(\$a['audio_commentary_path'])?>\"";$directShot="src=\"<?=h(\$a['screenshot_target_path'])?>\"";foreach(['annotation.php','home.php','explore.php','source.php','extension/sidepanel-state.js'] as $file){$body=(string)file_get_contents($root.'/'.$file);if(str_contains($body,$directAudio)||str_contains($body,$directShot)||str_contains($body,'API_BASE+a.audio_commentary_path')||str_contains($body,'API_BASE+a.screenshot_target_path'))$fail[]="Direct evidence URL exposure remains in $file.";}

$need('app/ai-access.php','function ai_interactive_model_record','Interactive AI entitlement must be centralized.');
$need('app/ai-access.php',"admin_enabled",'Interactive Admin AI must honor the model admin_enabled flag.');
$need('app/ai-access.php',"pro_enabled",'Interactive Pro AI must honor the model pro_enabled flag.');
$need('research-project.php','ai_interactive_model_record($pdo,$u,$model)','Ask Annotated must enforce centralized model entitlement.');
$need('admin/assistant.php','ai_interactive_model_record($pdo,$admin,$model)','Admin Assistant must enforce centralized model entitlement.');

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Security contracts passed.\n";
