<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$home=(string)file_get_contents($root.'/home.php');
$css=(string)file_get_contents($root.'/assets/css/app.css');
$js=(string)file_get_contents($root.'/assets/js/research-agent-workspace-ui.js');
$fail=[];

foreach([
  'data-research-desktop-library'=>'Desktop toolbar must expose one Library control.',
  'class="researchDesktopLibrary"'=>'Desktop Library control must be part of the canonical desktop action bar.',
  '/assets/css/app.css?v=57.0'=>'Home must request the Phase 57 CSS cache key.',
  '/assets/js/research-agent-workspace-ui.js?v=57.0'=>'Home must request the Phase 57 Research workspace JS cache key.',
] as $needle=>$message)if(!str_contains($home,$needle))$fail[]=$message;

foreach([
  "desktop.querySelector('[data-research-desktop-library]')?.addEventListener('click',openLibrary)"=>'Desktop Library button must reuse the existing Research Library drawer.',
] as $needle=>$message)if(!str_contains($js,$needle))$fail[]=$message;

foreach([
  '.homeFeedPage.researchDesktopMode .researchAgentCanvasTopActions'=>'Desktop mode must hide the duplicate floating Library/Desktop/X group.',
  '.researchDesktopActions .researchDesktopLibrary'=>'Desktop Library must be styled inside the existing action bar.',
  '.researchLibraryDocToolbar button{'=>'Library document toolbar must have an explicit button style.',
  'color:#1f2937!important;'=>'Library document toolbar buttons must use a visible dark foreground.',
  '.researchLibraryDocToolbar button:hover'=>'Library document toolbar hover/focus states must remain visible.',
  '.researchDesktopDocumentWindow .researchDocumentToolbar button'=>'Desktop document toolbar must retain visible foreground colors.',
] as $needle=>$message)if(!str_contains($css,$needle))$fail[]=$message;

$desktopStart=strpos($home,'<div class="researchDesktopActions">');
$desktopEnd=$desktopStart===false?false:strpos($home,'</div>',$desktopStart);
$libraryPos=strpos($home,'data-research-desktop-library');
$closePos=strpos($home,'data-research-desktop-close');
if($desktopStart===false||$desktopEnd===false||$libraryPos===false||$closePos===false||!($desktopStart<$libraryPos&&$libraryPos<$closePos&&$closePos<$desktopEnd))$fail[]='Desktop Library and Close controls must live in the same canonical desktop menu, with Library before Close.';

if(substr_count($home,'data-research-desktop-library')!==1)$fail[]='Desktop must render exactly one integrated Library control.';
if($fail){foreach($fail as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}
echo "Phase 55.3 Desktop menu + editor visibility contract passed.\n";
