<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/installer.php';

if(!function_exists('mb_substr'))throw new RuntimeException('Installer did not load runtime compatibility.');
if(mb_substr('café',0,3)!=='caf')throw new RuntimeException('Installer runtime compatibility is not UTF-8 safe.');

echo "Installer runtime compatibility contract passed.\n";
