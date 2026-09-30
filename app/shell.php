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
function app_shell_unread_count(PDO $pdo,array $user): int {
    try{
        return function_exists('notification_unread_count')?max(0,notification_unread_count($pdo,$user)):0;
    }catch(Throwable $e){
        return 0;
    }
}
function app_shell_notification_preview(PDO $pdo,array $user,int $limit=5): array {
    if(!function_exists('notification_rows'))return [];
    try{
        return array_slice(notification_rows($pdo,$user,max(1,min(12,$limit)),true),0,$limit);
    }catch(Throwable $e){
        return [];
    }
}
function app_shell_notification_time_label(string $createdAt): string {
    $createdAt=trim($createdAt);if($createdAt==='')return '';
    try{$dt=new DateTimeImmutable($createdAt);return $dt->format('M j · g:i A');}
    catch(Throwable $e){return $createdAt;}
}
function app_shell_notification_object_attrs(array $item): string {
    $map=['research_program'=>'program','research_intelligence_portfolio'=>'portfolio','research_report'=>'report'];
    $type=$map[(string)($item['object_type']??'')]??'';
    $public=trim((string)($item['object_public_id']??''));
    return $type!==''&&$public!==''?' data-object-detail-type="'.app_shell_h($type).'" data-object-detail-id="'.app_shell_h($public).'"':'';
}
function app_shell_header_notification(PDO $pdo,array $user,int $unread): string {
    $label='Notifications'.($unread>0?', '.$unread.' unread':'');
    $count=$unread>0?'<span class="appHeaderNotificationBadge">'.app_shell_h((string)min($unread,99)).($unread>99?'+':'').'</span>':'';
    $icon='<span class="appHeaderNotificationGlyph" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg></span>';
    $items=[];try{$items=function_exists('notification_rows')?array_slice(notification_rows($pdo,$user,40,false),0,40):[];}catch(Throwable $e){}
    $rows='';
    foreach($items as $item){
        $category=ucfirst((string)($item['category']??'Update'));
        $title=ucwords(str_replace('_',' ',(string)($item['notification_type']??'notification')));
        $body=trim((string)($item['body']??''));if(mb_strlen($body)>160)$body=mb_substr($body,0,157).'…';
        $url=(string)($item['url']??'');if($url===''||!str_starts_with($url,'/')||str_starts_with($url,'//'))$url='/notifications.php';
        $time=app_shell_notification_time_label((string)($item['created_at']??''));
        $unreadClass=empty($item['read_at'])?' unread':'';
        $rows.='<a class="notificationDrawerItem'.$unreadClass.'" href="'.app_shell_h($url).'"'.app_shell_notification_object_attrs($item).'><span class="notificationDrawerItemTop"><strong>'.app_shell_h($category).'</strong><small>'.app_shell_h($time).'</small></span><span class="notificationDrawerItemTitle">'.app_shell_h($title).'</span>'.($body!==''?'<span class="notificationDrawerItemBody">'.app_shell_h($body).'</span>':'').'</a>';
    }
    if($rows==='')$rows='<div class="notificationDrawerEmpty"><strong>You’re caught up.</strong><span>No notifications right now.</span></div>';
    return '<button type="button" class="appHeaderIcon appHeaderNotification" data-notification-drawer-open aria-label="'.app_shell_h($label).'" aria-controls="notification-activity-drawer">'.$icon.$count.'</button>'
      .'<aside class="notificationActivityDrawer" id="notification-activity-drawer" data-notification-drawer aria-hidden="true">'
      .'<header><div><span class="eyebrow">UPDATES</span><h2>Notifications &amp; Activity</h2></div><button type="button" data-notification-drawer-close aria-label="Close">×</button></header>'
      .'<nav class="notificationDrawerTabs" role="tablist"><button type="button" class="active" role="tab" aria-selected="true" data-notification-tab="notifications">Notifications'.($unread>0?' <span>'.$unread.'</span>':'').'</button><button type="button" role="tab" aria-selected="false" data-notification-tab="activity">Activity</button></nav>'
      .'<section class="notificationDrawerPanel active" data-notification-panel="notifications">'.$rows.'<a class="notificationDrawerAll" href="/notifications.php">View all notifications</a></section>'
      .'<section class="notificationDrawerPanel" data-notification-panel="activity" hidden><div class="notificationDrawerLoading" data-activity-loading>Loading activity…</div><div data-activity-list></div><a class="notificationDrawerAll" href="/activity.php">Open full activity timeline</a></section>'
      .'</aside><div class="notificationDrawerBackdrop" data-notification-drawer-backdrop hidden></div>';
}
function app_shell_object_detail_drawer(): string {
    return '<aside class="universalObjectDrawer" data-object-detail-drawer aria-hidden="true">'
      .'<header><div><span class="eyebrow" data-object-detail-type-label>OBJECT</span><h2 data-object-detail-title>Object detail</h2><small data-object-detail-meta></small></div><div><a data-object-detail-open href="#" aria-label="Open full page">↗</a><button type="button" data-object-detail-close aria-label="Close">×</button></div></header>'
      .'<nav class="universalObjectTabs" role="tablist">'
      .'<button type="button" class="active" data-object-tab="overview">Overview</button><button type="button" data-object-tab="activity">Activity</button><button type="button" data-object-tab="files">Files</button><button type="button" data-object-tab="people">People</button><button type="button" data-object-tab="links">Links</button><button type="button" data-object-tab="agent">Agent</button>'
      .'</nav>'
      .'<div class="universalObjectBody" data-object-detail-body><div class="universalObjectLoading">Loading…</div></div>'
      .'</aside><div class="universalObjectBackdrop" data-object-detail-backdrop hidden></div>';
}
function app_shell_link(string $href,string $label,string $icon,string $path,?string $match=null,string $badge=''): string {
    $active=$match!==null?str_starts_with($path,$match):$path===$href;
    return '<a class="appNavLink'.($active?' active':'').'" href="'.app_shell_h($href).'"><span class="appNavIcon" aria-hidden="true">'.$icon.'</span><span>'.$label.'</span>'.$badge.'</a>';
}
function app_shell_research_agent_rows(PDO $pdo,array $user,int $limit=30): array {
    try{
        return function_exists('research_agent_list')?research_agent_list($pdo,$user,$limit):[];
    }catch(Throwable $e){
        return [];
    }
}
function app_shell_research_agent_dialog(PDO $pdo,array $user): string {
    $options='<option value="">Personal</option>';
    if(function_exists('research_agent_workspace_options')){
        try{
            foreach(research_agent_workspace_options($pdo,$user) as $team){
                $options.='<option value="'.app_shell_h((string)$team['public_id']).'">'.app_shell_h((string)$team['name']).'</option>';
            }
        }catch(Throwable $e){}
    }
    return '<dialog class="researchAgentCreateDialog" data-research-agent-dialog data-csrf="'.app_shell_h(csrf_token()).'">'
      .'<form class="researchAgentCreateForm" data-research-agent-form>'
      .'<header><div><span class="eyebrow">RESEARCH AGENT</span><h2>New Research Agent</h2></div><button type="button" class="researchAgentDialogClose" data-research-agent-close aria-label="Close">×</button></header>'
      .'<p class="researchAgentCreateIntro">Create a dedicated research workspace and Agent. Add annotations as evidence, then let the Agent continue researching and monitoring that workspace.</p>'
      .'<div class="researchAgentCreateError" data-research-agent-error hidden></div>'
      .'<label>Name<input name="name" maxlength="190" required placeholder="e.g. Van Halen coverage monitor"></label>'
      .'<label>Research objective<textarea name="description" rows="4" maxlength="4000" placeholder="What should this Agent research, watch, compare, or follow?"></textarea></label>'
      .'<label>Workspace<select name="team_id">'.$options.'</select></label>'
      .'<label>Monitoring<select name="cadence"><option value="daily">Daily</option><option value="hourly">Hourly</option><option value="weekly">Weekly</option><option value="manual">Manual only</option></select></label>'
      .'<input type="hidden" name="timezone_name" value="UTC" data-research-agent-timezone>'
      .'<footer><button type="button" class="button secondary" data-research-agent-close>Cancel</button><button type="submit">Create Research Agent</button></footer>'
      .'</form></dialog>';
}
function app_shell_research_agents(PDO $pdo,array $user,string $path): string {
    if(function_exists('research_agent_ensure_default')){try{research_agent_ensure_default($pdo,$user);}catch(Throwable $e){}}
    $rows=app_shell_research_agent_rows($pdo,$user,30);
    $current=trim((string)($_GET['agent']??''));
    $items='';
    foreach($rows as $row){
        $conversation=trim((string)($row['conversation_public_id']??''));if($conversation==='')continue;
        $title=trim((string)($row['name']??''));if($title==='')$title='Research Agent';
        if(mb_strlen($title)>46)$title=mb_substr($title,0,43).'…';
        $last=trim((string)($row['last_message']??''));if($last!==''&&mb_strlen($last)>62)$last=mb_substr($last,0,59).'…';
        $cadence=trim((string)($row['monitoring_cadence']??'manual'));
        $meta=$last!==''?$last:(ucfirst($cadence).' monitoring');
        $active=$path==='/home.php'&&$current===$conversation;
        $items.='<a class="appShellAgentLink'.($active?' active':'').'" href="/home.php?agent='.rawurlencode($conversation).'"><span class="appShellAgentIcon" aria-hidden="true">✦</span><span class="appShellAgentCopy"><strong>'.app_shell_h($title).'</strong><small>'.app_shell_h($meta).'</small></span></a>';
    }
    if($items==='')$items='<div class="appShellAgentEmpty">No research agents yet.</div>';
    $head='<div class="appShellSectionHeader"><div class="appShellSectionTitle">Research Agents</div><button type="button" class="appShellSectionAdd" data-research-agent-add aria-label="Add Research Agent" title="New Research Agent">+</button></div>';
    return '<section class="appShellAgentSection" aria-label="Research Agents">'.$head.'<div class="appShellAgentList">'.$items.'</div></section>'.app_shell_research_agent_dialog($pdo,$user);
}
function app_shell_research_project_rows(PDO $pdo,array $user,int $limit=30): array {
    $limit=max(1,min(50,$limit));
    try{
        $agentExclusion=(function_exists('research_agent_ready')&&research_agent_ready($pdo))?" AND NOT EXISTS(SELECT 1 FROM research_agents rag WHERE rag.project_id=rp.id)":"";
        $q=$pdo->prepare("SELECT rp.public_id,rp.title,rp.status,rp.updated_at,t.name team_name
          FROM research_projects rp
          LEFT JOIN teams t ON t.id=rp.team_id
          WHERE rp.status<>'archived'
            AND (rp.owner_user_id=? OR EXISTS(SELECT 1 FROM team_members tm WHERE tm.team_id=rp.team_id AND tm.user_id=?))".$agentExclusion."
          ORDER BY rp.updated_at DESC,rp.id DESC
          LIMIT ".$limit);
        $q->execute([(int)$user['id'],(int)$user['id']]);
        return $q->fetchAll()?:[];
    }catch(Throwable $e){
        return [];
    }
}
function app_shell_research_projects(PDO $pdo,array $user,string $path): string {
    $rows=app_shell_research_project_rows($pdo,$user,30);
    if(!$rows)return '';
    $current=trim((string)($_GET['id']??$_GET['project']??''));
    $items='';
    foreach($rows as $row){
        $id=trim((string)($row['public_id']??''));if($id==='')continue;
        $title=trim((string)($row['title']??''));if($title==='')$title='Untitled research';
        if(mb_strlen($title)>46)$title=mb_substr($title,0,43).'…';
        $team=trim((string)($row['team_name']??''));$status=trim((string)($row['status']??'active'));
        $meta=$team!==''?$team:ucfirst($status);
        $active=$current===$id&&str_starts_with($path,'/research');
        $items.='<a class="appShellProjectLink'.($active?' active':'').'" href="/research-project.php?id='.rawurlencode($id).'"><span class="appShellProjectIcon" aria-hidden="true">▤</span><span class="appShellProjectCopy"><strong>'.app_shell_h($title).'</strong><small>'.app_shell_h($meta).'</small></span></a>';
    }
    return $items===''?'':'<section class="appShellProjectSection" aria-label="Research Projects"><div class="appShellSectionTitle">Research Projects</div><div class="appShellProjectList">'.$items.'</div></section>';
}

function app_shell_user_nav(PDO $pdo,array $user,string $path,?int $unread=null): string {
    $teamCount=0;
    try{$q=$pdo->prepare('SELECT COUNT(*) FROM team_members WHERE user_id=?');$q->execute([$user['id']]);$teamCount=(int)$q->fetchColumn();}catch(Throwable $e){}
    $links=[];
    $links[]=app_shell_link('/home.php','Home','⌂',$path);
    $links[]=app_shell_link('/explore.php','Explore','◎',$path);
    $links[]=app_shell_link('/teams.php','Teams','♙',$path,null,app_shell_badge($teamCount));
    $links[]=app_shell_link('/research.php','Research','▤',$path,'/research');
    $links[]=app_shell_link('/live.php','Live','◉',$path);
    $links[]=app_shell_link('/saved.php','Saved','◇',$path,'/saved');
    return implode('',$links);
}
function app_shell_admin_nav(string $path): string {
    $links=[];
    $links[]=app_shell_link('/admin/','Admin Home','▦',$path,'/admin/index.php');
    $links[]=app_shell_link('/admin/users.php','Users','♙',$path);
    $links[]=app_shell_link('/admin/billing.php','Billing & Stripe','¤',$path);
    $links[]=app_shell_link('/admin/ai.php','AI & LLM','✦',$path);
    $links[]=app_shell_link('/admin/source-monitor.php','Source Monitor','◌',$path);
    $links[]=app_shell_link('/admin/moderation.php','Moderation','⚑',$path);
    $links[]=app_shell_link('/admin/discovery-entities.php','Discovery','◎',$path);
    $links[]=app_shell_link('/admin/data-attribution.php','Data Governance','⌘',$path);
    $links[]=app_shell_link('/admin/datasets.php','Dataset Registry','▤',$path);
    $links[]=app_shell_link('/admin/evaluations.php','Evaluation Harness','✓',$path);
    $links[]=app_shell_link('/admin/model-registry.php','Model Registry','◇',$path);
    $links[]=app_shell_link('/admin/training.php','Training Registry','⚙',$path);
    $links[]=app_shell_link('/admin/post-training.php','Post-Training Readiness','◎',$path);
    $links[]=app_shell_link('/admin/model-release.php','Release Decisions','✍',$path);
    $links[]=app_shell_link('/admin/model-deployment.php','Model Deployments','⇢',$path);
    $links[]=app_shell_link('/admin/model-observability.php','Model Health','◉',$path);
    $links[]=app_shell_link('/admin/model-improvements.php','Model Improvements','↻',$path);
    $links[]=app_shell_link('/admin/model-campaigns.php','Improvement Campaigns','⇄',$path);
    $links[]=app_shell_link('/admin/intelligence-release-audit.php','Intelligence Release Audit','✓',$path);
    $links[]=app_shell_link('/admin/system-health.php','System Health','◫',$path);
    $links[]=app_shell_link('/upgrade.php','Database Upgrade','⇧',$path);
    $links[]=app_shell_link('/admin/assistant.php','Admin Assistant','⌁',$path);
    return implode('',$links);
}
function app_shell_search(): string {
    return '<form class="appHeaderSearch" action="/search.php" method="get" data-command-palette-open><span aria-hidden="true">⌕</span><input name="q" aria-label="Search Annotated" autocomplete="off" readonly placeholder="Search Annotated or run a command"><kbd>⌘K</kbd></form>';
}
function app_shell_command_palette(): string {
    return '<dialog class="commandPalette" data-command-palette aria-label="Search Annotated and run commands">'
      .'<div class="commandPaletteHead"><span aria-hidden="true">⌕</span><input type="search" data-command-input autocomplete="off" placeholder="Search agents, missions, tasks, teams, sources…"><button type="button" class="commandPaletteClose" data-command-close aria-label="Close">×</button></div>'
      .'<div class="commandPaletteResults" data-command-results role="listbox" aria-label="Search results"></div>'
      .'<div class="commandPaletteEmpty" data-command-empty>Type to search everything, or choose an action.</div>'
      .'<div class="commandPaletteHint"><span>↑ ↓ navigate · Enter open</span><span>Esc close · / search</span></div>'
      .'</dialog>';
}
function app_shell_create_launcher(PDO $pdo,array $user): string {
    $context=function_exists('research_object_context_from_request')?research_object_context_from_request($pdo,$user):[];
    $contextType=(string)($context['type']??'');$contextId=(string)($context['public_id']??'');
    $shortcutHtml='';
    if(function_exists('research_object_shortcuts')){
        try{
            foreach(research_object_shortcuts($pdo,$user,6) as $shortcut){
                $pin=!empty($shortcut['pinned_at'])?'<span aria-label="Pinned" title="Pinned">★</span>':'';
                $shortcutHtml.='<a class="appCreateShortcut" href="'.app_shell_h((string)$shortcut['url']).'"><span><strong>'.app_shell_h((string)$shortcut['title']).'</strong><small>'.app_shell_h(ucwords(str_replace('_',' ',(string)$shortcut['object_type']))).'</small></span>'.$pin.'</a>';
            }
        }catch(Throwable $e){}
    }
    $agents=app_shell_research_agent_rows($pdo,$user,60);
    $agentOptions='';$currentAgent='';
    $requested=trim((string)($_GET['agent']??''));
    foreach($agents as $agent){
        $public=(string)($agent['public_id']??'');$conversation=(string)($agent['conversation_public_id']??'');if($public==='')continue;
        if($requested!==''&&($requested===$public||$requested===$conversation))$currentAgent=$public;
        $agentOptions.='<option value="'.app_shell_h($public).'"'.($currentAgent===$public?' selected':'').'>'.app_shell_h((string)($agent['name']??'Research Agent')).'</option>';
    }
    if($agentOptions==='')$agentOptions='<option value="">Create a Research Agent first</option>';
    $teamOptions='<option value="">Personal</option>';
    if(function_exists('research_agent_workspace_options')){try{foreach(research_agent_workspace_options($pdo,$user) as $team)$teamOptions.='<option value="'.app_shell_h((string)$team['public_id']).'">'.app_shell_h((string)$team['name']).'</option>';}catch(Throwable $e){}}
    $decisionOptions='<option value="">Select an accepted Decision</option>';
    try{
        if(function_exists('research_decisions_ready')&&research_decisions_ready($pdo)){
            $q=$pdo->prepare("SELECT rd.public_id,rd.title,ra.name agent_name FROM research_decisions rd JOIN research_agents ra ON ra.id=rd.research_agent_id LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=? WHERE rd.status IN ('accepted','reopened') AND ((ra.team_id IS NULL AND ra.owner_user_id=?) OR (ra.team_id IS NOT NULL AND tm.user_id=?)) ORDER BY rd.updated_at DESC LIMIT 80");
            $q->execute([(int)$user['id'],(int)$user['id'],(int)$user['id']]);
            foreach($q->fetchAll() as $d)$decisionOptions.='<option value="'.app_shell_h((string)$d['public_id']).'">'.app_shell_h((string)$d['title']).' · '.app_shell_h((string)$d['agent_name']).'</option>';
        }
    }catch(Throwable $e){}
    $agentSelect='<label>Research Agent<select name="agent_id" required>'.$agentOptions.'</select></label>';
    $teamSelect='<label>Workspace<select name="team_id">'.$teamOptions.'</select></label>';
    $actions=[
      ['research_agent','✦','Research Agent','Dedicated Agent + research workspace'],
      ['portfolio','▦','Portfolio','Roll up programs, decisions, risk and briefings'],
      ['mission','◎','Mission','Durable research objective with success criteria'],
      ['task','✓','Task','Queue a concrete research task'],
      ['program','↻','Program','Recurring research and follow-through'],
      ['decision','◇','Decision','Record a conclusion or formal decision'],
      ['action_plan','→','Action Plan','Turn an accepted Decision into execution'],
      ['document','▤','Document','Create a Research workspace document'],
      ['report','≡','Report','Generate a Research Agent report'],
      ['sticky','□','Sticky Note','Add a note to the Agent Desktop'],
      ['team','♙','Team','Create a shared research workspace'],
      ['source','⌁','Add Source','Add a URL to Annotated']
    ];
    $cards='';foreach($actions as [$key,$icon,$title,$desc])$cards.='<button type="button" class="appCreateChoice" data-create-action="'.app_shell_h($key).'"><span class="appCreateChoiceIcon">'.$icon.'</span><span><strong>'.app_shell_h($title).'</strong><small>'.app_shell_h($desc).'</small></span><i>→</i></button>';
    $panel=function(string $key,string $title,string $fields,string $submit): string {
        return '<form class="appCreateForm" data-create-panel="'.app_shell_h($key).'" hidden><header><button type="button" class="appCreateBack" data-create-back>←</button><div><span class="eyebrow">CREATE</span><h2>'.app_shell_h($title).'</h2></div><button type="button" class="appCreateClose" data-create-close aria-label="Close">×</button></header><div class="appCreateError" data-create-error hidden></div><div class="appCreateFields">'.$fields.'</div><footer><button type="button" class="button secondary" data-create-back>Back</button><button type="submit">'.$submit.'</button></footer></form>';
    };
    $panels='';
    $panels.=$panel('research_agent','Research Agent','<label>Name<input name="name" maxlength="190" required placeholder="Research Agent name"></label><label>Research objective<textarea name="description" rows="4" maxlength="4000" placeholder="What should this Agent research or monitor?"></textarea></label>'.$teamSelect.'<label>Monitoring<select name="cadence"><option value="daily">Daily</option><option value="hourly">Hourly</option><option value="weekly">Weekly</option><option value="manual">Manual only</option></select></label><input type="hidden" name="timezone_name" value="UTC">','Create Research Agent');
    $panels.=$panel('portfolio','Portfolio','<label>Title<input name="title" maxlength="255" required placeholder="Portfolio title"></label><label>Objective<textarea name="objective" rows="4" maxlength="16000" required placeholder="What should this Portfolio track across Research Programs?"></textarea></label>'.$teamSelect.'<label>Briefing cadence<select name="briefing_cadence"><option value="manual">Manual</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option><option value="quarterly">Quarterly</option></select></label><input type="hidden" name="timezone_name" value="UTC">','Create Portfolio');
    $panels.=$panel('mission','Mission',$agentSelect.'<label>Mission title<input name="title" maxlength="255" required></label><label>Research question<textarea name="research_question" rows="3" maxlength="16000" required></textarea></label><label>Objective<textarea name="objective" rows="4" maxlength="16000" required></textarea></label><label>Priority<select name="priority"><option value="medium">Medium</option><option value="high">High</option><option value="urgent">Urgent</option><option value="low">Low</option></select></label><label>Success criteria <small>one per line</small><textarea name="criteria_lines" rows="4"></textarea></label>','Create Mission');
    $panels.=$panel('task','Task',$agentSelect.'<label>Task title<input name="title" maxlength="255" required></label><label>Description<textarea name="description" rows="4" maxlength="12000"></textarea></label><label>Type<select name="task_type"><option value="general">General research</option><option value="find_source">Find source</option><option value="verify_claim">Verify claim</option><option value="review_source_change">Review source change</option></select></label><label>Priority<select name="priority"><option value="medium">Medium</option><option value="high">High</option><option value="urgent">Urgent</option><option value="low">Low</option></select></label><label>Due<input type="datetime-local" name="due_at"></label>','Create Task');
    $panels.=$panel('program','Program',$agentSelect.'<label>Program title<input name="title" maxlength="255" required></label><label>Objective<textarea name="objective" rows="4" maxlength="16000" required></textarea></label><label>Cadence<select name="cadence"><option value="weekly">Weekly</option><option value="daily">Daily</option><option value="hourly">Hourly</option><option value="monthly">Monthly</option><option value="manual">Manual only</option></select></label><label>Priority<select name="priority"><option value="medium">Medium</option><option value="high">High</option><option value="urgent">Urgent</option><option value="low">Low</option></select></label><input type="hidden" name="timezone_name" value="UTC">','Create Program');
    $panels.=$panel('decision','Decision',$agentSelect.'<label>Title<input name="title" maxlength="255" required></label><label>Decision / conclusion<textarea name="statement" rows="4" maxlength="64000" required></textarea></label><label>Rationale<textarea name="rationale" rows="4" maxlength="64000"></textarea></label><label>Confidence <small>0–1</small><input type="number" name="confidence" min="0" max="1" step="0.01"></label>','Create Decision');
    $panels.=$panel('action_plan','Action Plan','<label>Decision<select name="decision_id" required>'.$decisionOptions.'</select></label><label>Title<input name="title" maxlength="255" placeholder="Defaults from Decision"></label><label>Objective<textarea name="objective" rows="4" maxlength="16000" required></textarea></label><label>Expected result<textarea name="expected_result" rows="3" maxlength="16000" required></textarea></label><label>Priority<select name="priority"><option value="medium">Medium</option><option value="high">High</option><option value="urgent">Urgent</option><option value="low">Low</option></select></label>','Create Action Plan');
    $panels.=$panel('document','Document',$agentSelect.'<label>Title<input name="title" maxlength="240" required></label><label>Type<select name="document_type"><option value="document">Document</option><option value="research_brief">Research Brief</option><option value="memo">Memo</option><option value="report">Report</option><option value="analysis">Analysis</option><option value="source_summary">Source Summary</option><option value="timeline">Timeline</option><option value="weekly_report">Weekly Report</option></select></label><label>Content<textarea name="body" rows="8" maxlength="50000"></textarea></label>','Create Document');
    $panels.=$panel('report','Report',$agentSelect.'<label>Report type<select name="report_type"><option value="research_brief">Research Brief</option><option value="full_intelligence">Full Intelligence Report</option><option value="evidence_audit">Evidence Audit</option><option value="claims_verification">Claims & Verification</option><option value="contradictions_gaps">Contradictions & Gaps</option><option value="source_freshness">Source Freshness & Change</option><option value="timeline">Research Timeline</option><option value="action_plan">Research Action Plan</option><option value="mission_brief">Mission Brief</option><option value="mission_review">Mission Review</option></select></label><label>Custom title<input name="title" maxlength="240" placeholder="Optional"></label>','Generate Report');
    $panels.=$panel('sticky','Sticky Note',$agentSelect.'<label>Title<input name="title" maxlength="240" placeholder="Sticky note"></label><label>Note<textarea name="body" rows="6" maxlength="10000" required></textarea></label><label>Color<select name="color"><option value="yellow">Yellow</option><option value="blue">Blue</option><option value="green">Green</option><option value="pink">Pink</option><option value="purple">Purple</option></select></label>','Create Sticky Note');
    $panels.=$panel('team','Team','<label>Team name<input name="name" maxlength="190" required placeholder="Research Team"></label>','Create Team');
    $panels.=$panel('source','Add Source','<label>URL<input type="url" name="url" required placeholder="https://example.com/article"></label><label>Title<input name="title" maxlength="500" placeholder="Optional source title"></label>','Add Source');
    return '<button type="button" class="appHeaderCreate" data-create-launcher-open aria-label="Create" title="Create">+</button>'
      .'<dialog class="appCreateDialog" data-create-launcher data-csrf="'.app_shell_h(csrf_token()).'" data-context-type="'.app_shell_h($contextType).'" data-context-id="'.app_shell_h($contextId).'"><section class="appCreateMenu" data-create-menu><header><div><span class="eyebrow">CREATE</span><h2>What do you want to create?</h2><p>Start something new without leaving your current workspace.</p></div><button type="button" class="appCreateClose" data-create-close aria-label="Close">×</button></header><div class="appCreateGrid">'.$cards.'</div>'.($shortcutHtml!==''?'<section class="appCreateShortcuts" data-create-shortcuts><div class="eyebrow">RECENT &amp; PINNED</div><div>'.$shortcutHtml.'</div></section>':'').'</section>'.$panels.'</dialog>';
}
function app_shell_object_context_bar(PDO $pdo,array $user): string {
    if(!function_exists('research_object_context_from_request')||!function_exists('research_object_descriptor'))return '';
    try{
        $context=research_object_context_from_request($pdo,$user);if(!$context)return '';
        $d=research_object_descriptor($pdo,$user,$context);if(!$d)return '';
        if(function_exists('research_object_recent_touch'))research_object_recent_touch($pdo,$user,(string)$d['type'],(string)$d['public_id'],(string)$d['title'],(string)$d['url']);
        $pinned=false;
        if(function_exists('research_object_shortcuts'))foreach(research_object_shortcuts($pdo,$user,20) as $s)if((string)$s['object_type']===(string)$d['type']&&(string)$s['object_public_id']===(string)$d['public_id']){$pinned=!empty($s['pinned_at']);break;}
        $meta=array_values(array_filter([(string)($d['status']??''),(string)($d['agent_name']??''),(string)($d['team_name']??'')],fn($v)=>trim($v)!==''));
        return '<section class="appObjectContextBar" data-object-context-bar data-object-type="'.app_shell_h((string)$d['type']).'" data-object-id="'.app_shell_h((string)$d['public_id']).'"><div><span class="eyebrow">'.app_shell_h((string)$d['type_label']).'</span><strong>'.app_shell_h((string)$d['title']).'</strong>'.($meta?'<small>'.app_shell_h(implode(' · ',$meta)).'</small>':'').'</div><nav><button type="button" data-object-pin aria-pressed="'.($pinned?'true':'false').'">'.($pinned?'★ Pinned':'☆ Pin').'</button><a href="'.app_shell_h((string)$d['url']).'">Open</a></nav></section>';
    }catch(Throwable $e){return '';}
}
function app_shell_mobile_nav(PDO $pdo,array $user,string $path,bool $adminMode,int $unread=0): string {
    $links=$adminMode?app_shell_admin_nav($path):app_shell_user_nav($pdo,$user,$path,$unread);
    return '<details class="appMobileMenu"><summary aria-label="Open navigation">☰</summary><div class="appMobileMenuPanel">'.$links.'</div></details>';
}
function app_shell_user_menu(array $user,bool $isAdmin): string {
    $username=(string)($user['username']??'');
    $profile=profile_path($username);
    return '<details class="appUserMenu"><summary aria-label="Open profile menu">'.app_shell_avatar($user,'appAvatar').'</summary><div class="appUserDropdown"><a href="'.app_shell_h($profile).'">View profile</a><a href="/settings.php">Settings</a><a href="/chrome-extension.php">Chrome Extension</a><a href="/billing.php">Billing</a><a href="/account-members.php">Account members</a><a href="/connected-accounts.php">Connected accounts</a><a href="/data-attribution.php">Data & Attribution</a><a href="/onboarding.php">Onboarding</a>'.($isAdmin?'<a href="/admin/">Admin</a>':'').'<hr><a href="/logout.php">Sign out</a></div></details>';
}
function app_shell_markup(PDO $pdo,array $user): array {
    $path=app_shell_request_path();
    $isAdmin=(string)($user['role']??'')==='admin';
    $adminMode=$isAdmin&&(str_starts_with($path,'/admin/')||$path==='/upgrade.php');
    $unread=app_shell_unread_count($pdo,$user);
    $brand='<a class="appShellBrand" href="'.($adminMode?'/admin/':'/home.php').'"><span class="appShellMark">A</span><span>Annotated</span></a>';
    $nav=$adminMode?app_shell_admin_nav($path):app_shell_user_nav($pdo,$user,$path,$unread);
    $aside='<aside class="appShellSidebar">'.$brand;
    if($adminMode)$aside.='<div class="appShellRole">ADMIN WORKSPACE</div>';
    $aside.='<nav class="appShellNav" aria-label="'.($adminMode?'Admin':'Application').' navigation">'.$nav.'</nav>';
    if($adminMode){
        $aside.='<div class="appShellSidebarBottom"><a class="appShellExtension secondaryShellAction" href="/home.php">← Back to social app</a></div>';
    }else{
        $aside.=app_shell_research_agents($pdo,$user,$path).app_shell_research_projects($pdo,$user,$path);
    }
    $aside.='</aside>';
    $create=$adminMode?'':app_shell_create_launcher($pdo,$user);$palette=$adminMode?'':app_shell_command_palette();$objectDrawer=$adminMode?'':app_shell_object_detail_drawer();$header='<header class="appShellHeader">'.app_shell_mobile_nav($pdo,$user,$path,$adminMode,$unread).'<div class="appHeaderBrandMobile">'.$brand.'</div>'.app_shell_search().'<div class="appHeaderActions">'.$create.app_shell_header_notification($pdo,$user,$unread).app_shell_user_menu($user,$isAdmin).'</div></header>'.$palette.$objectDrawer; $objectBar=$adminMode?'':app_shell_object_context_bar($pdo,$user);
    $footer='';
    return [$aside,$header,$footer];
}
function app_shell_transform(string $html): string {
    $state=$GLOBALS['annotated_shell']??null;
    if(!$state||!is_string($html)||stripos($html,'<html')===false||stripos($html,'<body')===false)return $html;
    if(str_contains($html,'data-annotated-shell="1"'))return $html;
    if(!str_contains($html,'/assets/css/create-launcher.css'))$html=(string)preg_replace('#</head>#i','<link rel="stylesheet" href="/assets/css/create-launcher.css?v=74.3"><link rel="stylesheet" href="/assets/css/command-palette.css?v=74.4"><link rel="stylesheet" href="/assets/css/shell-drawers.css?v=74.5"></head>',$html,1);
    $pdo=$state['pdo']??null;$user=$state['user']??null;
    if(!$pdo instanceof PDO||!is_array($user))return $html;

    // Remove legacy page-local top bars. The universal shell owns product navigation.
    $html=(string)preg_replace('#<header\s+class=["\']topbar["\'][^>]*>.*?</header>#is','',$html,1);

    [$aside,$header,$footer]=app_shell_markup($pdo,$user);
    $mode=(string)($state['mode']??'full');$headerOnly=$mode==='header_only';
    $open='<div class="appShell'.($headerOnly?' appShellHeaderOnly':'').'" data-annotated-shell="1" data-chat-presence-csrf="'.app_shell_h(csrf_token()).'">'.($headerOnly?'':$aside).'<div class="appShellStage">'.$header.($headerOnly?'':$objectBar).'<div class="appShellContent">';
    $presenceScript=(function_exists('conversation_presence_ready')&&conversation_presence_ready($pdo))?'<script src="/assets/js/chat-presence.js?v=12.0"></script>':'';
    $researchAgentScript=($headerOnly?'':'<script src="/assets/js/research-agent-shell.js?v=47.0"></script>').'<script src="/assets/js/create-launcher.js?v=1.0"></script>'.($headerOnly?'':'<script src="/assets/js/command-palette.js?v=74.5"></script><script src="/assets/js/shell-drawers.js?v=74.5"></script>');
    $close='</div>'.$footer.'</div></div>'.$presenceScript.$researchAgentScript;

    $html=(string)preg_replace('#<body([^>]*)>#i','<body$1>'.$open,$html,1);
    $pos=strripos($html,'</body>');
    if($pos!==false)$html=substr($html,0,$pos).$close.substr($html,$pos);
    return $html;
}
