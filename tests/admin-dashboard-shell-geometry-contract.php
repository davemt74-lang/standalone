<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $p)use($root,&$fail): string{$f=$root.'/'.$p;if(!is_file($f)){$fail[]='Missing '.$p;return '';}return (string)file_get_contents($f);};
$need=function(string $p,string $n,string $m)use($read,&$fail): void{$c=$read($p);if($c!==''&&!str_contains($c,$n))$fail[]=$m;};
$avoid=function(string $p,string $n,string $m)use($read,&$fail): void{$c=$read($p);if($c!==''&&str_contains($c,$n))$fail[]=$m;};

$need('app/shell.php','$isAdminDashboard','Admin shell must identify the Dashboard separately.');
$need('app/shell.php',"\$headerSearch=\$isAdminDashboard?'':app_shell_search();",'Dashboard must suppress the universal header search.');
$need('app/shell.php','appShellHeaderAdmin','Admin pages must expose a dedicated header geometry hook.');
$need('assets/css/app.css','/* Admin shell geometry hardening — center every admin workspace in the canvas. */','Admin shell geometry hardening must exist.');
$need('assets/css/app.css','width:calc(100% - 268px)!important','Admin content width must exclude the fixed sidebar.');
$need('assets/css/app.css','margin:0 0 0 268px!important','Admin content must begin after the fixed sidebar.');
$need('assets/css/app.css','.adminSidebar~main.panel>*','All Admin page content must share the centered canvas rule.');
$need('assets/css/app.css','width:min(100%,1480px)!important','Admin page content must be centered within a bounded working canvas.');
$need('assets/css/app.css','.appShellHeaderAdmin .appHeaderSearch','Admin header search must be constrained to the available canvas.');
$need('admin/index.php','ADMIN COMMAND CENTER','Dashboard must present a command-center overview.');
$need('admin/index.php','adminExecutiveGrid','Dashboard must include executive status.');
$need('admin/index.php','adminOperationsOverview','Dashboard must include cross-functional operations summaries.');
$need('admin/index.php','CUSTOMER & SUPPORT','Dashboard must include customer/support health.');
$need('admin/index.php','BILLING & FINANCE','Dashboard must include billing/finance health.');
$need('admin/index.php','AI & AUTOMATION','Dashboard must include AI operations.');
$need('admin/index.php','SECURITY & PLATFORM','Dashboard must include security/platform readiness.');
$avoid('admin/index.php','class="adminDashboardSearch"','Dashboard must not render its old local search bar.');
$avoid('admin/index.php','admin_ops_global_search($pdo','Dashboard must not perform the removed local search query.');
if($fail){fwrite(STDERR,implode("\n",array_unique($fail))."\n");exit(1);}
echo "Admin shell centering and comprehensive dashboard contract passed.\n";
