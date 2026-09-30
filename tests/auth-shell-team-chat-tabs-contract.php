<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $p)use($root,&$fail): string{$f=$root.'/'.$p;if(!is_file($f)){$fail[]='Missing '.$p;return '';}return (string)file_get_contents($f);};
$shell=$read('app/shell.php');
if(str_contains($shell,'<footer class="appShellFooter">'))$fail[]='Authenticated application shell must not render the public footer.';
if(str_contains($shell,'class="appUserSummary"'))$fail[]='Authenticated header user control must not render display name or username.';
if(!str_contains($shell,'<summary aria-label="Open profile menu">'))$fail[]='Authenticated header must keep an accessible avatar-only profile menu.';
if(!str_contains($shell,"app_shell_avatar(\$user,'appAvatar')"))$fail[]='Authenticated header must retain the profile avatar.';
$home=$read('home.php');
if(!str_contains($home,'data-team-chat-tab="chat"')||!str_contains($home,'data-team-chat-tab="members"'))$fail[]='Team Chat must expose Chat and Members tabs.';
if(!str_contains($home,'id="teamChatMembers"')||!str_contains($home,'data-team-chat-panel="members"'))$fail[]='Members roster must live in the Members tab.';
if(!str_contains($home,'id="teamChatMessages"')||!str_contains($home,'data-team-chat-panel="chat"'))$fail[]='Active conversation must live in the Chat tab.';
if($fail){fwrite(STDERR,implode("\n",array_unique($fail))."\n");exit(1);}echo "Authenticated shell and Team Chat tabs contract passed.\n";
