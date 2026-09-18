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
$need('app/live.php','project_can_write($project)','Research Live room writes must reject viewer-only project access.');
$need('app/live.php','presence_identity_visible($pdo','Live presence and messages must use the centralized identity-disclosure policy.');
$need('source.php','public_discovery_source($pdo,$id,$viewer)','Source pages must route through centralized viewer-scoped discovery access.');
$need('source-compare.php','source_access($pdo,$id,$viewer)','Source comparison must enforce source visibility.');
$need('search.php','public_discovery_search($pdo,$term,$viewer)','Search must route through public-only viewer-aware discovery services.');
$need('annotation.php','public_discovery_annotation($pdo,$id,$viewer)','Direct annotation pages must route through centralized viewer-scoped discovery access.');
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


$need('app/rich-capture.php','private_storage_allocate','Rich Capture uploads must use private storage.');
$need('app/rich-capture.php','rich_capture_assert_ebml','Rich Capture uploads must validate the WebM container signature.');
$need('app/rich-capture.php','Media upload offset mismatch','Rich Capture chunks must enforce exact offsets.');
$need('app/rich-capture.php','rich_capture_max_bytes','Rich Capture uploads must enforce a server-side byte ceiling.');
$need('api/extension-capture.php','require_api_mutation_auth($pdo)','Rich Capture upload mutations must require authenticated extension mutation access.');
$need('api/extension-publish.php','rich_capture_upload_for_publish','Publishing media must consume an authenticated private upload.');
$need('api/extension-publish.php','rich_capture_project_for_publish','Unified capture-to-Research must enforce project write/visibility compatibility.');
$need('extension/service-worker.js','chrome.tabCapture.getMediaStreamId','Media capture must be user-initiated through Chrome tabCapture.');
$need('worker/media-worker.php','input_is_clip','Media processing must distinguish already-clipped tab input from source timestamps.');
$need('worker/media-worker.php','scale=-2:240','Video derivatives must remain 240p.');
$avoid('worker/media-worker.php','yt-dlp','Media worker must not include provider download/bypass tooling.');
$avoid('worker/media-worker.php','youtube-dl','Media worker must not include provider download/bypass tooling.');
$avoid('app/evidence-access.php','media_uploads','Original Rich Capture upload inputs must never be exposed through evidence delivery.');

$need('app/feed.php','feed_access_sql','Phase 6 feeds must centralize visibility filtering.');
$need('app/feed.php','project_annotations','Phase 6 feed visibility must include explicit project access without making private items public.');
$need('app/feed.php','feed_block_sql','Phase 6 feeds must enforce block relationships server-side.');
$need('api/extension-feed.php','feed_annotation_rows','This Page and Following must use server-side feed access services.');
$need('api/extension-feed.php','require_api_mutation_auth($pdo)','Feed read, comment, and source-follow mutations must require authenticated mutation access.');
$need('app/functions.php','source_identity_url','Declared canonical URLs must pass source identity validation.');
$need('app/functions.php','source_resolve_url','Canonical source aliases must resolve server-side.');
$need('extension/content.js','declaredCanonicalUrl','The extension must detect declared canonical source identity.');
$need('extension/sidepanel-feed.js','phase6OpenContext','Original-context actions must be explicit rather than exposing private evidence URLs.');
$avoid('app/feed.php','media_uploads','Feed delivery must never expose raw Rich Capture upload inputs.');

$need('app/public-discovery.php',"a.visibility='public'",'Phase 7 Explore/Search/Profile annotation discovery must be public-only.');
$need('app/public-discovery.php',"rr.visibility='public'",'Phase 7 public Research discovery must exclude Team and Private reports.');
$need('app/public-discovery.php','annotation_access($pdo,$publicId,$viewer)','Direct annotation pages must retain centralized viewer-scoped authorization.');
$need('app/public-discovery.php','source_access($pdo,$publicId,$viewer)','Direct source pages must retain centralized viewer-scoped authorization.');
$need('app/public-discovery.php','is_blocked($pdo,$uid','Phase 7 direct annotation discovery must reject blocked relationships.');
$need('app/public-discovery.php','NOT EXISTS(SELECT 1 FROM blocks','Phase 7 signed-in Explore/Search must filter blocked relationships server-side.');
$need('source.php','noindex,nofollow','Non-public source views must be marked noindex.');
$need('annotation.php','noindex,nofollow','Non-public annotation views must be marked noindex.');
$need('research-report.php','noindex,nofollow','Non-public Research report views must be marked noindex.');
$need('research-report.php','research_report_version_access($pdo,$report,$version,$viewer)','Research report pages must re-check historical version visibility.');
$need('research-report-export.php','research_report_version_access($pdo,$report,$version,$viewer)','Research report exports must re-check historical version visibility.');
$need('search.php','noindex,follow','Search results pages must not become duplicate public index pages.');
foreach(['source.php','annotation.php','profile.php','research-report.php','research-report-export.php'] as $file)$need($file,'Cache-Control: private, no-store',"Viewer-scoped public response must not be shared-cached: $file");

$need('app/live.php','live_room_scope','Phase 8 Live authorization must be centralized.');
$need('app/live.php',"JOIN team_members tm ON tm.team_id=t.id WHERE t.public_id=? AND tm.user_id=?",'Team Live rooms must require server-side membership.');
$need('app/live.php','project_access($pdo,$uid','Research Live rooms must inherit project access.');
$need('app/live.php',"last_seen_at<DATE_SUB(NOW(),INTERVAL 90 SECOND)",'Stale Live presence must be expired server-side.');
$need('app/live.php',"NOT EXISTS(SELECT 1 FROM blocks b",'Live message delivery must enforce block relationships server-side.');
$need('app/live.php',"\$identityVisible=\$m['identity_mode']==='visible'",'Live message identity disclosure must be conditional.');
$need('app/live.php',"else \$row['cloak_alias']",'Cloaked Live messages must serialize only a pseudonym.');
$avoid('app/live.php',"'user_public_id'=>\$m['user_public_id']", 'Live payloads must not unconditionally serialize internal author identity fields.');
$need('app/live.php','client_message_id','Live message creation must support retry idempotency.');
$need('app/live.php','annotation_access($pdo','Live activity events must re-check current annotation authorization.');
$need('api/extension-live.php','require_api_mutation_auth($pdo)','Live mutations must require authenticated mutation access.');
foreach(['live_message_delete','live_message_pin','live_react','live_leave'] as $action)$need('api/extension-live.php',"$action","Phase 8 Live API action missing: $action");
$need('extension/sidepanel-state.js','liveClientSessionId','Chrome Live must persist a client session identity.');
$need('extension/sidepanel-social.js','client_message_id','Chrome Live sends must carry an idempotency key.');
$need('extension/sidepanel-social.js','reveal_identity','Live identity reveal must be explicit per message.');
$need('live.php','require_csrf()','Website Live mutations must require CSRF protection.');
$need('live.php','Cache-Control: private, no-store','Website Live must not be shared-cached.');
$need('live.php','noindex,nofollow','Authenticated Live pages must not be indexed.');
$phase8Migration=(string)file_get_contents($root.'/database/migrations/20260917_014_live_rooms_cloak.sql');foreach(['uq_live_presence_client','uq_live_client_message'] as $needle)if(!str_contains($phase8Migration,$needle))$fail[]="Phase 8 idempotency/session migration contract missing: $needle";


$need('app/notifications.php','notification_object_access($pdo,$viewer,$n)','Notification delivery must revalidate object access at read time.');
$need('app/notifications.php','is_blocked($pdo,$userId,$actorUserId)','Notification creation must suppress blocked actors.');
$need('app/notifications.php','notification_is_muted','Notification creation must enforce user mutes server-side.');
$need('app/notifications.php','dedupe_key','Notifications must support server-side deduplication.');
$avoid('api/extension-trust.php',"SELECT * FROM notifications",'Extension notification delivery must use centralized access-aware notification services.');
$need('api/extension-trust.php','notification_rows($pdo,$u','Extension notification reads must use centralized access-aware delivery.');
$need('app/source-integrity.php','source_annotation_impacts','Source integrity must persist per-annotation impact state.');
$need('app/source-integrity.php',"'passage_missing'",'Source integrity must distinguish missing referenced passages.');
$need('app/source-integrity.php',"'source_unavailable'",'Source integrity must distinguish source outages.');
$need('app/moderation.php','moderation_target($pdo','Moderation reports must resolve targets through centralized visibility checks.');
$need('app/moderation.php','moderation_action_record','Moderator decisions must create immutable audit actions.');
$need('app/moderation.php','tracking_token_hash','Anonymous claim tracking must store only a token hash.');
$need('app/moderation.php','hash_equals','Claim tracking token comparison must be timing-safe.');
$need('app/access.php','source_moderation_status','Annotation access must inherit source moderation restriction.');
foreach(['notifications.php','settings.php','claim-status.php','report-status.php','admin/moderation.php'] as $file)$need($file,'Cache-Control: private, no-store',"Phase 9 private workflow must not be shared-cached: $file");

$avoid('app/public-discovery.php','media_uploads','Public discovery must never query or expose raw Rich Capture uploads.');
$need('app/ai-access.php','function ai_interactive_model_record','Interactive AI entitlement must be centralized.');
$need('app/ai-access.php',"admin_enabled",'Interactive Admin AI must honor the model admin_enabled flag.');
$need('app/ai-access.php',"pro_enabled",'Interactive Pro AI must honor the model pro_enabled flag.');
$need('research-project.php','ai_interactive_model_record($pdo,$u,$model)','Ask Annotated must enforce centralized model entitlement.');
$need('admin/assistant.php','ai_interactive_model_record($pdo,$admin,$model)','Admin Assistant must enforce centralized model entitlement.');

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Security contracts passed.\n";
