<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$home=(string)file_get_contents($root.'/home.php');
$css=(string)file_get_contents($root.'/assets/css/app.css');
$js=(string)file_get_contents($root.'/assets/js/research-agent-workspace-ui.js');
$fail=[];

foreach([
  'width:min(560px,100vw)!important;'=>'Research Library drawer must be widened for document editing.',
  '.researchLibraryDrawer.is-viewing-item>.researchLibraryDrawerHeader'=>'Document view must suppress the duplicate Library header.',
  'position:fixed!important;'=>'Research canvas controls must escape the centered canvas constraint.',
  'top:74px!important;'=>'Research canvas controls must sit directly below the application header.',
  'right:18px!important;'=>'Research canvas controls must align under the profile controls.',
  'font-size:8px!important;'=>'Library and Desktop control labels must be reduced by 2px.',
  'font-size:16px!important;'=>'Close control glyph must be reduced by 2px.',
  '.researchLibraryViewer[data-mode="document"] [data-research-library-doc-panel]'=>'Document viewer must have an explicit document display mode.',
] as $needle=>$message)if(!str_contains($css,$needle))$fail[]=$message;

foreach([
  "libraryViewer.dataset.mode=String(item.object_type||'item').toLowerCase()"=>'Library viewer must assign an explicit object mode.',
  "const html=String(doc.content_html||'').trim(),plain=String(doc.document_plain_text||'').trim()"=>'Document viewer must load HTML with a plain-text fallback.',
  "setLibraryDocState('Loading…')"=>'Document viewer must expose loading state instead of a blank panel.',
  "libraryDocContent.contentEditable=canWrite()?'true':'false'"=>'Document viewer must preserve live write permissions.',
] as $needle=>$message)if(!str_contains($js,$needle))$fail[]=$message;

$outerHeader=strpos($home,'class="researchLibraryDrawerHeader"');
$viewerHeader=strpos($home,'class="researchLibraryViewerHeader"');
if($outerHeader===false||$viewerHeader===false||$outerHeader>$viewerHeader)$fail[]='Library and document viewer header order is invalid.';
if(!str_contains($home,'/assets/css/app.css?v=57.0'))$fail[]='Home must request the Phase 55.2 CSS cache key.';
if(!str_contains($home,'/assets/js/research-agent-workspace-ui.js?v=57.0'))$fail[]='Home must request the Phase 55.3 workspace JS cache key.';

if($fail){foreach($fail as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}
echo "Phase 55.2 Library document UI contract passed.\n";
