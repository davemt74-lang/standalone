<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $p)use($root,&$fail): string{$f=$root.'/'.$p;if(!is_file($f)){$fail[]='Missing '.$p;return '';}return (string)file_get_contents($f);};
$need=function(string $p,string $n,string $m)use($read,&$fail): void{$c=$read($p);if($c!==''&&!str_contains($c,$n))$fail[]=$m;};
$avoid=function(string $p,string $n,string $m)use($read,&$fail): void{$c=$read($p);if($c!==''&&str_contains($c,$n))$fail[]=$m;};

$need('research-intelligence-portfolios.php','/research-intelligence-portfolio-create.php','Portfolio index must expose a dedicated New Portfolio action.');
$avoid('research-intelligence-portfolios.php','<aside class="card intelligencePortfolioCreate">','Portfolio index must not embed the Portfolio creation form.');
$avoid('research-intelligence-portfolios.php',"$op==='create'",'Portfolio index must not own Portfolio creation submission.');
$need('research-intelligence-portfolio-create.php','research_intelligence_portfolio_create','Dedicated create page must use the canonical Portfolio engine.');
$need('research-intelligence-portfolio-create.php','Create Portfolio','Dedicated create page must render the Portfolio form.');
$need('research-intelligence-portfolio-create.php','/research-intelligence-portfolios.php?view=portfolios&portfolio=','Successful creation must route back to the canonical Portfolio detail.');
$need('research-intelligence-portfolio-create.php','require_csrf();','Dedicated create page must retain CSRF protection.');
$need('research-intelligence-portfolio-create.php','assets/css/portfolio-create.css?v=74.3','Dedicated page must load its isolated layout CSS.');
$need('assets/css/portfolio-create.css','.portfolioCreateStandalone','Dedicated create form must not inherit the old sticky sidebar layout.');
if($fail){fwrite(STDERR,implode("\n",array_unique($fail))."\n");exit(1);}echo "Portfolio dedicated create surface contract passed.\n";
