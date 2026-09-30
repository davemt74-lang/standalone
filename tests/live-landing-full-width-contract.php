<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $p)use($root,&$fail): string{$f=$root.'/'.$p;if(!is_file($f)){$fail[]='Missing '.$p;return '';}return (string)file_get_contents($f);};
$need=function(string $p,string $n,string $m)use($read,&$fail): void{$c=$read($p);if($c!==''&&!str_contains($c,$n))$fail[]=$m;};
$avoid=function(string $p,string $n,string $m)use($read,&$fail): void{$c=$read($p);if($c!==''&&str_contains($c,$n))$fail[]=$m;};

$need('live.php','class="liveLandingCanvas"','Live landing must use the full-width canvas.');
$need('live.php','class="liveLandingHero"','Live landing must have an engaging hero.');
$need('live.php','class="liveLandingGrid liveLandingGridActive"','Live landing must surface active rooms prominently.');
$need('live.php','class="liveLandingPrivate"','Live landing must preserve private Team/Research access context.');
$avoid('live.php','<main class="panel">','Live landing must not use the legacy white panel container.');
$need('assets/css/app.css','.liveLandingCanvas{','Live full-width canvas styling must exist.');
$need('assets/css/app.css','max-width:none','Live landing must not be constrained to the legacy centered panel width.');
$need('assets/css/app.css','grid-template-columns:repeat(3,minmax(0,1fr))','Live source/room cards must use the expanded canvas.');
$need('assets/css/app.css','@media(max-width:680px)','Live landing must retain mobile responsiveness.');
if($fail){fwrite(STDERR,implode("\n",array_unique($fail))."\n");exit(1);}
echo "Live landing full-width UI contract passed.\n";
