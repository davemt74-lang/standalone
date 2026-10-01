<?php
declare(strict_types=1);
/** Offline-only owner token setup. Never expose this script through HTTP. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/installer.php';
$root=dirname(__DIR__);
$file=installer_setup_token_path($root);
$token=bin2hex(random_bytes(32));
if(is_link($file)||is_file($file)){fwrite(STDERR,"The owner setup token already exists outside the web root; do not overwrite it.\n");exit(1);}
$handle=@fopen($file,'x');
if($handle===false){fwrite(STDERR,"Unable to create owner token file in parent directory. Generate it using your hosting file manager instead.\n");exit(2);}
@chmod($file,0600);
$ok=fwrite($handle,$token."\n");
fclose($handle);
if($ok!==65){@unlink($file);fwrite(STDERR,"Could not write a complete owner token.\n");exit(2);}
echo "Created the owner setup token outside the web root: ".$file."\n";
echo "Enter the token below when authorizing /install.php and creating /first-admin.php. Do not save it in screenshots, URLs or public logs.\n";
echo $token."\n";
