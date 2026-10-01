<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $p)use($root,&$fail): string{$f=$root.'/'.$p;if(!is_file($f)){$fail[]='Missing '.$p;return '';}return (string)file_get_contents($f);};
$need=function(string $p,string $n,string $m)use($read,&$fail): void{$c=$read($p);if($c!==''&&!str_contains($c,$n))$fail[]=$m;};
$avoid=function(string $p,string $n,string $m)use($read,&$fail): void{$c=$read($p);if($c!==''&&str_contains($c,$n))$fail[]=$m;};

$avoid('app/admin-ui.php','Admin V2.90 · Proactive Admin Intelligence','Admin sidebar footer must not show version history.');
$need('app/admin-ui.php','<div class="adminSidebarFoot"><a href="/logout.php">Sign out</a>','Admin sidebar footer should keep only useful account controls.');
$avoid('admin/index.php','One operations workspace for accounts','Dashboard must not render the old long intro paragraph.');
$avoid('admin/index.php','ADMIN V2.80 · CONTEXTUAL ADMIN AGENT','Dashboard must not render version history eyebrow copy.');
$need('admin/index.php','class="adminDashboardHeader"','Dashboard must use the compact operational header.');
$avoid('admin/index.php','class="adminDashboardSearch"','Dashboard must not render a dedicated search bar.');
$need('admin/index.php','ADMIN COMMAND CENTER','Dashboard must render the comprehensive command-center header.');
$need('assets/css/app.css','width:min(100%,var(--admin-canvas-max))','Admin pages must share a centered bounded canvas.');
$need('assets/css/app.css','/* Admin shell + dashboard refresh — Sep 2026 */','Admin shell refresh styles must ship.');
$need('assets/css/app.css','background:#f7f7f5!important','Admin sidebar must match the main canvas background.');
$need('assets/css/app.css','margin:0 0 0 var(--admin-nav-width)','Admin canvas must use the full right column.');
$site=$read('assets/css/app.css');$ext=$read('extension/landing-app.css');if($site!==''&&$ext!==''&&!hash_equals(hash('sha256',$site),hash('sha256',$ext)))$fail[]='Extension landing CSS must remain byte-identical to website CSS.';
if($fail){fwrite(STDERR,implode("\n",array_unique($fail))."\n");exit(1);}echo "Admin shell and dashboard refresh contract passed.\n";
