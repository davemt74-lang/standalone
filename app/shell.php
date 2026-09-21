<?php
declare(strict_types=1);

/**
 * Universal authenticated Annotated application shell.
 *
 * Product pages keep ownership of their page content while this layer owns
 * navigation, identity, role-aware controls, extension access and footer.
 */
function app_shell_h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function app_shell_request_path(): string {
    $path=(string)(parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/');
    return $path===''?'/':$path;
}
function app_shell_is_html_candidate(): bool {
    if(PHP_SAPI==='cli')return false;
    $path=app_shell_request_path();
    foreach(['/api/','/evidence.php','/research-report-export.php'] as $skip){
        if($skip==='/api/'?str_starts_with($path,$skip):$path===$skip)return false;
    }
    return true;
}
function app_shell_activate(PDO $pdo,array $user): void {
    if(!empty($GLOBALS['annotated_shell_disabled'])||!app_shell_is_html_candidate())return;
    $mode=(string)($GLOBALS['annotated_shell_mode']??'full');if(!in_array($mode,['full','header_only'],true))$mode='full';
    $GLOBALS['annotated_shell']=['pdo'=>$pdo,'user'=>$user,'mode'=>$mode];
    if(empty($GLOBALS['annotated_shell_buffering'])){
        $GLOBALS['annotated_shell_buffering']=true;
        ob_start('app_shell_transform');
    }
}
function app_shell_avatar(array $user,string $class='appAvatar'): string {
    $name=(string)($user['display_name']??$user['username']??'A');
    $image=trim((string)($user['profile_image_url']??''));
    if($image!=='')return '<img class="'.app_shell_h($class).'" src="'.app_shell_h($image).'" alt="'.app_shell_h($name).'">';
    $initial=mb_strtoupper(mb_substr($name!==''?$name:'A',0,1));
    return '<span class="'.app_shell_h($class).' appAvatarFallback">'.app_shell_h($initial).'</span>';
}
function app_shell_badge(int $count): string {
    return $count>0?'<span class="appNavBadge">'.app_shell_h((string)min($count,99)).($count>99?'+':'').'</span>':'';
}
function app_shell_link(string $href,string $label,string $icon,string $path,?string $match=null,string $badge=''): string {
    $active=$match!==null?str_starts_with($path,$match):$path===$href;
    return '<a class="appNavLink'.($active?' active':'').'" href="'.app_shell_h($href).'"><span class="appNavIcon" aria-hidden="true">'.$icon.'</span><span>'.$label.'</span>'.$badge.'</a>';
}
function app_shell_user_nav(PDO $pdo,array $user,string $path): string {
    $unread=0;$teamCount=0;
    try{if(function_exists('notification_unread_count'))$unread=notification_unread_count($pdo,$user);}catch(Throwable $e){}
    try{$q=$pdo->prepare('SELECT COUNT(*) FROM team_members WHERE user_id=?');$q->execute([$user['id']]);$teamCount=(int)$q->fetchColumn();}catch(Throwable $e){}
    $pro=false;try{$pro=function_exists('user_is_pro')&&user_is_pro($pdo,$user);}catch(Throwable $e){}
    $links=[];
    $links[]=app_shell_link('/home.php','Home','⌂',$path);
    $links[]=app_shell_link('/explore.php','Explore','◎',$path);
    $links[]=app_shell_link('/search.php','Search','⌕',$path);
    $links[]=app_shell_link('/teams.php','Teams','♙',$path,null,app_shell_badge($teamCount));
    $links[]=app_shell_link('/research.php','Research','▤',$path,'/research');
    $links[]=app_shell_link('/live.php','Live','◉',$path);
    $links[]=app_shell_link('/saved.php','Saved','◇',$path,'/saved');
    if(function_exists('data_attribution_ready')&&data_attribution_ready($pdo))$links[]=app_shell_link('/data-attribution.php','Data & Attribution','⌘',$path);
    $links[]=app_shell_link('/notifications.php','Notifications','♢',$path,null,app_shell_badge($unread));
    if($pro)$links[]=app_shell_link('/ai.php','Ask Annotated','✦',$path);
    return implode('',$links);
}
function app_shell_admin_nav(string $path): string {
    $links=[];
    $links[]=app_shell_link('/admin/','Admin Home','▦',$path,'/admin/index.php');
    $links[]=app_shell_link('/admin/users.php','Users','♙',$path);
    $links[]=app_shell_link('/admin/ai.php','AI & LLM','✦',$path);
    $links[]=app_shell_link('/admin/source-monitor.php','Source Monitor','◌',$path);
    $links[]=app_shell_link('/admin/moderation.php','Moderation','⚑',$path);
    $links[]=app_shell_link('/admin/discovery-entities.php','Discovery','◎',$path);
    $links[]=app_shell_link('/admin/data-attribution.php','Data Governance','⌘',$path);
    $links[]=app_shell_link('/admin/system-health.php','System Health','◫',$path);
    $links[]=app_shell_link('/upgrade.php','Database Upgrade','⇧',$path);
    $links[]=app_shell_link('/admin/assistant.php','Admin Assistant','⌁',$path);
    return implode('',$links);
}
function app_shell_search(): string {
    return '<form class="appHeaderSearch" action="/search.php" method="get"><span aria-hidden="true">⌕</span><input name="q" aria-label="Search Annotated" placeholder="Search people, sources, annotations, research"></form>';
}
function app_shell_mobile_nav(PDO $pdo,array $user,string $path,bool $adminMode): string {
    $links=$adminMode?app_shell_admin_nav($path):app_shell_user_nav($pdo,$user,$path);
    return '<details class="appMobileMenu"><summary aria-label="Open navigation">☰</summary><div class="appMobileMenuPanel">'.$links.'</div></details>';
}
function app_shell_user_menu(array $user,bool $isAdmin): string {
    $username=(string)($user['username']??'');
    $profile=profile_path($username);
    return '<details class="appUserMenu"><summary>'.app_shell_avatar($user,'appAvatar').'<span class="appUserSummary"><strong>'.app_shell_h((string)($user['display_name']??$username)).'</strong><small>@'.app_shell_h($username).'</small></span><span aria-hidden="true">⌄</span></summary><div class="appUserDropdown"><a href="'.app_shell_h($profile).'">View profile</a><a href="/settings.php">Settings</a><a href="/connected-accounts.php">Connected accounts</a><a href="/data-attribution.php">Data & attribution</a><a href="/onboarding.php">Onboarding</a>'.($isAdmin?'<a href="/admin/">Admin</a>':'').'<hr><a href="/logout.php">Sign out</a></div></details>';
}
function app_shell_markup(PDO $pdo,array $user): array {
    $path=app_shell_request_path();
    $isAdmin=(string)($user['role']??'')==='admin';
    $adminMode=$isAdmin&&(str_starts_with($path,'/admin/')||$path==='/upgrade.php');
    $brand='<a class="appShellBrand" href="'.($adminMode?'/admin/':'/home.php').'"><span class="appShellMark">A</span><span>Annotated</span></a>';
    $nav=$adminMode?app_shell_admin_nav($path):app_shell_user_nav($pdo,$user,$path);
    $roleText=$adminMode?'ADMIN WORKSPACE':'SOCIAL RESEARCH';
    $aside='<aside class="appShellSidebar">'.$brand.'<div class="appShellRole">'.app_shell_h($roleText).'</div><nav class="appShellNav" aria-label="'.($adminMode?'Admin':'Application').' navigation">'.$nav.'</nav>';
    if($adminMode){
        $aside.='<div class="appShellSidebarBottom"><a class="appShellExtension secondaryShellAction" href="/home.php">← Back to social app</a></div>';
    }else{
        $aside.='<div class="appShellSidebarBottom"><a class="appShellExtension" href="/chrome-extension.php"><span>⬇</span><span><strong>Chrome Extension</strong><small>Capture from any page</small></span></a>'.($isAdmin?'<a class="secondaryShellAction" href="/admin/">Open Admin</a>':'').'</div>';
    }
    $aside.='</aside>';
    $header='<header class="appShellHeader">'.app_shell_mobile_nav($pdo,$user,$path,$adminMode).'<div class="appHeaderBrandMobile">'.$brand.'</div>'.app_shell_search().'<div class="appHeaderActions"><a class="appHeaderIcon" href="/notifications.php" aria-label="Notifications">♢</a>'.app_shell_user_menu($user,$isAdmin).'</div></header>';
    $footer='<footer class="appShellFooter"><span>Annotated · Research the web in context.</span><nav><a href="/explore.php">Explore</a><a href="/teams.php">Teams</a><a href="/chrome-extension.php">Chrome Extension</a><a href="/settings.php">Privacy & Settings</a></nav></footer>';
    return [$aside,$header,$footer];
}
function app_shell_transform(string $html): string {
    $state=$GLOBALS['annotated_shell']??null;
    if(!$state||!is_string($html)||stripos($html,'<html')===false||stripos($html,'<body')===false)return $html;
    if(str_contains($html,'data-annotated-shell="1"'))return $html;
    $pdo=$state['pdo']??null;$user=$state['user']??null;
    if(!$pdo instanceof PDO||!is_array($user))return $html;

    // Remove legacy page-local top bars. The universal shell owns product navigation.
    $html=(string)preg_replace('#<header\s+class=["\']topbar["\'][^>]*>.*?</header>#is','',$html,1);

    [$aside,$header,$footer]=app_shell_markup($pdo,$user);
    $mode=(string)($state['mode']??'full');$headerOnly=$mode==='header_only';
    $open='<div class="appShell'.($headerOnly?' appShellHeaderOnly':'').'" data-annotated-shell="1" data-chat-presence-csrf="'.app_shell_h(csrf_token()).'">'.($headerOnly?'':$aside).'<div class="appShellStage">'.$header.'<div class="appShellContent">';
    $presenceScript=(function_exists('conversation_presence_ready')&&conversation_presence_ready($pdo))?'<script src="/assets/js/chat-presence.js?v=12.0"></script>':'';
    $close='</div>'.$footer.'</div></div>'.$presenceScript;

    $html=(string)preg_replace('#<body([^>]*)>#i','<body$1>'.$open,$html,1);
    $pos=strripos($html,'</body>');
    if($pos!==false)$html=substr($html,0,$pos).$close.substr($html,$pos);
    return $html;
}
