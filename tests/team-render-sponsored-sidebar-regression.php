<?php
declare(strict_types=1);
/**
 * Execute actual page templates with safe fixtures to catch undefined helpers
 * that PHP lint and string-matching UI contracts alone cannot detect.
 */
$root=dirname(__DIR__);
function h(mixed $v): string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function csrf_token(): string {return str_repeat('a',64);}
function profile_path(string $username): string {return '/user.php?u='.rawurlencode($username);}
function app_shell_avatar(array $row,string $class=''):string{return '<span class="'.h($class).'">avatar</span>';}
function research_agent_shell_href(array $agent,string $tab):string{return '/research.php?agent='.rawurlencode((string)($agent['public_id']??'')).'&tab='.rawurlencode($tab);}
function annotation_ui_card(array $row,array $viewer):string{return '<div>Annotation content</div>';}
function annotation_ui_scripts(array $viewer):string{return '';}
function checkRender(bool $ok,string $detail):void{if(!$ok)throw new RuntimeException('Render regression: '.$detail);}
function pageMarkup(string $filename):string {
    global $root;
    $source=file_get_contents($root.'/'.$filename);
    checkRender(is_string($source),'missing '.$filename);
    $point=strpos($source,'?><!doctype');
    checkRender($point!==false,'HTML document boundary missing in '.$filename);
    return substr($source,$point);
}
function renderTemplate(string $template):string {
    extract($GLOBALS,EXTR_SKIP);
    ob_start();
    try{eval($template);}catch(Throwable $e){ob_end_clean();throw $e;}
    return (string)ob_get_clean();
}
$u=['id'=>11,'public_id'=>'user-11'];
$team=['id'=>5,'public_id'=>'team-5','name'=>'Workola','access_role'=>'owner','owner_user_id'=>11];
$isOwner=true;$canManage=true;$error='';$success='';
$members=[['id'=>11,'username'=>'owner','display_name'=>'Owner','role'=>'owner'],['id'=>12,'username'=>'researcher','display_name'=>'Researcher','role'=>'researcher']];
$resources=[['id'=>21,'public_id'=>'agent-21','name'=>'Sample Agent','project_title'=>'Sample Project','owner_user_id'=>11,'owner_name'=>'Owner']];
$assignable=[['public_id'=>'agent-22','name'=>'Unassigned personal Agent','project_title'=>'Personal Project']];
$projects=[['public_id'=>'project-5','title'=>'Team Research Project','description'=>'Description','status'=>'active','updated_at'=>'2026-10-01']];
$annotations=[['public_id'=>'annotation-5']];
$canContributeResearch=true;
$teamRecentResearch=[['public_id'=>'document-8','object_type'=>'document','title'=>'Evidence review','updated_at'=>'2026-10-01','contributor_name'=>'Researcher','last_editor_name'=>'Owner','agent_name'=>'Sample Agent','agent_public_id'=>'agent-21','conversation_public_id'=>'chat-21','document_summary'=>'Key research findings','team_public_id'=>'team-5','team_name'=>'Workola','tag_label'=>'Team · Workola','origin_team_contribution'=>true]];
$teamHtml=renderTemplate(pageMarkup('team.php'));
foreach(['Add a member','name="csrf"','name="username"','Attach Research Agent','name="agent_id"','Assigned Research Agents, Desktops','Team Research Contributions','Open Desktop','Open Library','Add research to Team Library','Team · Workola','Team-created','Evidence review','Contributed by Researcher','Projects','Recent annotations','Remove from Team','</main>'] as $needle)
    checkRender(str_contains($teamHtml,$needle),'team.php missing '.$needle);
checkRender(str_contains($teamHtml,'data-team-contribution="team-5"'),'Contribution badges must identify current Team context without granting access.');

checkRender(strpos($teamHtml,'Attach Research Agent')<strpos($teamHtml,'Recent annotations'),'Team page cuts off content below member card.');
checkRender(!str_contains($teamHtml,'<aside>'),'Team page must not restore old right sidebar.');
$canContributeResearch=false;
$viewerHtml=renderTemplate(pageMarkup('team.php'));
checkRender(str_contains($viewerHtml,'view-only'),'Viewer must see explicit read-only collaboration status.');
checkRender(!str_contains($viewerHtml,'name="body"'),'Viewer must not receive a research document composer.');
$resources=[];$teamRecentResearch=[];
$unassignedHtml=renderTemplate(pageMarkup('team.php'));
checkRender(str_contains($unassignedHtml,'Attaching an Agent is optional'),'Teams without Agents must still render and remain usable.');
$canContributeResearch=true;
$agentId='agent-22';
$agent=['public_id'=>'agent-22','name'=>'Unassigned personal Agent','visibility'=>'private','status'=>'active','monitoring_cadence'=>'daily','profile_image_url'=>'','description'=>'Research purpose'];
$project=['title'=>'Personal Research','description'=>'Private project'];
$automation=['weekday'=>1,'prompt'=>''];$missions=$plans=$programs=$watches=$portfolios=$drafts=[];
$timezone='UTC';$runTime='09:00';
$storyPolicy=['stories_enabled'=>false,'publish_mode'=>'approval','min_priority'=>'medium','daily_story_cap'=>3,'timezone_name'=>'UTC','quiet_hours_enabled'=>false,'quiet_start'=>'','quiet_end'=>''];
$ownedTeams=[['id'=>5,'public_id'=>'team-5','name'=>'Workola','owner_user_id'=>11]];
$assignedTeam=null;$canAttachTeam=true;
$editHtml=renderTemplate(pageMarkup('research-agent-edit.php'));
foreach(['Assign to a Team','Optional:','name="team_id"','Select a Team','Save Agent','Proactive publishing','MISSIONS','PORTFOLIOS','</html>'] as $needle)
    checkRender(str_contains($editHtml,$needle),'personal Agent editor missing '.$needle);
checkRender(strpos($editHtml,'Assign to a Team')<strpos($editHtml,'Save Agent'),'Optional Team settings must not replace normal Agent editor.');
$assignedTeam=['id'=>5,'public_id'=>'team-5','name'=>'Workola','owner_user_id'=>11,'owner_member'=>1];$canAttachTeam=false;
$assignedHtml=renderTemplate(pageMarkup('research-agent-edit.php'));
checkRender(str_contains($assignedHtml,'Remove from Team'),'Assigned Agent must allow owner to unassign.');
checkRender(str_contains($assignedHtml,'Save Agent'),'Team assignment cannot hide normal editor.');
$shell=file_get_contents($root.'/app/shell.php');
checkRender(str_contains((string)$shell,"app_shell_link('/research-projects.php','Sponsored Research'"),'Main sidebar missing Sponsored Research route.');
checkRender(is_file($root.'/research-projects.php'),'Sponsored Research route is missing.');
foreach(['team.php','research-agent-edit.php'] as $page)
    checkRender(!str_contains((string)file_get_contents($root.'/'.$page),'csrf_field()'),'Undefined csrf_field() would fatally truncate '.$page);
echo "Team page, optional Agent editor, owner assignment, member form and Sponsored Research navigation render correctly.\n";
