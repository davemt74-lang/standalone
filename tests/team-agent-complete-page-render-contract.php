<?php
declare(strict_types=1);
/**
 * Render the ACTUAL PHP page templates, not merely their static strings.
 * Catch undefined helpers/early fatal errors that PHP lint cannot detect.
 * Routes' login/DB bootstrap is deliberately replaced with representative,
 * already-authorized view fixtures; DB behavior is covered by its DB journey.
 */
$root=dirname(__DIR__);
require_once $root.'/app/functions.php';
require_once $root.'/app/research-agent-shell-ui.php';
function app_shell_avatar(array $person,string $class=''): string {return '<span class="'.h($class).'">A</span>';}
function annotation_ui_card(array $annotation,array $viewer): string {return '<article class="annotationFixture">Annotation preserved</article>';}
function annotation_ui_scripts(array $viewer): string {return '<script data-annotation-scripts></script>';}
$_SESSION=['csrf'=>'render-fixture-token'];
function render_real_page(string $path,array $vars): string {
    $source=file_get_contents(dirname(__DIR__).'/'.$path);
    if(!is_string($source))throw new RuntimeException('Unable to read '.$path);
    if(preg_match('/\\bcsrf_field\\s*\\(/',$source))
        throw new RuntimeException($path.' invokes undefined csrf_field() instead of csrf_token().');
    $at=strpos($source,'?><!doctype html>');
    if($at===false)throw new RuntimeException('Expected render boundary is missing in '.$path);
    $template=substr($source,$at+2);
    extract($vars,EXTR_SKIP);
    $before=ob_get_level();ob_start();
    try {eval('?>'.$template);return (string)ob_get_clean();}
    catch(Throwable $e) {
        while(ob_get_level()>$before)ob_end_clean();
        throw new RuntimeException($path.' failed to finish rendering: '.$e->getMessage(),0,$e);
    }
}
function assert_ui(bool $ok,string $label): void {
    if(!$ok)throw new RuntimeException('FAIL: '.$label);
    echo "PASS: $label\n";
}
function xpath_page(string $html): DOMXPath {
    $dom=new DOMDocument();
    $prior=libxml_use_internal_errors(true);
    try {$ok=$dom->loadHTML($html,LIBXML_NONET);}
    finally {libxml_clear_errors();libxml_use_internal_errors($prior);}
    if(!$ok)throw new RuntimeException('Unable to parse rendered HTML.');
    return new DOMXPath($dom);
}
$viewer=['id'=>11,'public_id'=>'user-fixture','username'=>'owner','display_name'=>'Owner'];
$team=['id'=>17,'public_id'=>'team-fixture','name'=>'Workola','owner_user_id'=>11,'access_role'=>'owner'];
$agent=['id'=>23,'public_id'=>'agent-fixture','name'=>'Festo','description'=>'My personal research',
        'owner_user_id'=>11,'conversation_public_id'=>'conversation-fixture',
        'visibility'=>'private','status'=>'active','monitoring_cadence'=>'manual',
        'profile_image_url'=>null,'project_public_id'=>'project-fixture'];
$resource=array_merge($agent,['owner_name'=>'Owner','project_title'=>'Personal Project']);
$teamVars=['u'=>$viewer,'team'=>$team,'isOwner'=>true,'canManage'=>true,'error'=>'','success'=>'',
    'members'=>[['id'=>11,'username'=>'owner','display_name'=>'Owner','role'=>'owner','profile_image_url'=>null],
                ['id'=>12,'username'=>'researcher','display_name'=>'Researcher','role'=>'researcher','profile_image_url'=>null]],
    'resources'=>[$resource],'assignable'=>[['public_id'=>'another-agent','name'=>'Another Agent','project_title'=>'Another Project']],
    'projects'=>[['public_id'=>'project-fixture','title'=>'Shared Research Project','status'=>'active','updated_at'=>'2026-10-01','description'=>'Shared evidence']],
    'annotations'=>[['public_id'=>'annotation-fixture']]];
$teamHtml=render_real_page('team.php',$teamVars);
$x=xpath_page($teamHtml);
assert_ui(str_contains($teamHtml,'</html>')&&str_contains($teamHtml,'Recent annotations'),'Team page renders through the entire feed/footer.');
assert_ui($x->query('//main[contains(@class,"teamWorkspaceFullWidth")]//section[@id="team-agent-attachment"]')->length===1,
    'Attach Research Agent remains visible within the main full-width Team workspace.');
assert_ui($x->query('//form[input[@name="op" and @value="invite"] and .//input[@name="username"] and .//input[@name="csrf"]]')->length===1,
    'Add Member renders a visible working POST form with CSRF and username.');
assert_ui($x->query('//form[input[@name="op" and @value="assign_agent"] and .//select[@name="agent_id"] and .//input[@name="csrf"]]')->length===1,
    'Owned Agent picker and submit form actually render.');
assert_ui(str_contains($teamHtml,'Shared Research Project')&&str_contains($teamHtml,'Annotation preserved'),
    'Team research projects and annotations remain visible after the member controls.');
$teamVars['resources']=[];$teamVars['assignable']=[];$teamVars['projects']=[];$teamVars['annotations']=[];
$teamEmpty=render_real_page('team.php',$teamVars);
assert_ui(str_contains($teamEmpty,'</html>')&&str_contains($teamEmpty,'Your Team can still collaborate without an Agent'),
    'A Team without an Agent renders fully and remains usable.');
$teamVars['isOwner']=false;$teamVars['canManage']=false;$teamVars['team']['access_role']='researcher';
$memberTeam=render_real_page('team.php',$teamVars);
$memberXpath=xpath_page($memberTeam);
assert_ui($memberXpath->query('//form[input[@name="op" and @value="assign_agent"]]')->length===0 &&
          $memberXpath->query('//form[input[@name="op" and @value="invite"]]')->length===0,
    'Non-managing Team members cannot see privileged attachment/invitation forms.');
$policy=['stories_enabled'=>false,'publish_mode'=>'approval','min_priority'=>'medium',
    'daily_story_cap'=>3,'quiet_hours_enabled'=>false,'timezone_name'=>'UTC','quiet_start'=>'',
    'quiet_end'=>'','trigger_evidence'=>false,'trigger_risk'=>false,'trigger_question'=>false,
    'trigger_decision'=>false,'trigger_task'=>false,'trigger_update'=>false];
$agentVars=['u'=>$viewer,'agentId'=>'agent-fixture','agent'=>$agent,
    'project'=>['title'=>'Personal Project','description'=>'Personal work','status'=>'active'],
    'automation'=>['timezone_name'=>'UTC','run_time_local'=>'09:00','weekday'=>1,'prompt'=>''],
    'missions'=>[],'plans'=>[],'programs'=>[],'watches'=>[],'portfolios'=>[],'drafts'=>[],
    'storyPolicy'=>$policy,'timezone'=>'UTC','runTime'=>'09:00','error'=>'','success'=>'',
    'ownedTeams'=>[$team],'assignedTeam'=>null,'canAttachTeam'=>true];
$agentHtml=render_real_page('research-agent-edit.php',$agentVars);
$a=xpath_page($agentHtml);
assert_ui(str_contains($agentHtml,'</html>')&&str_contains($agentHtml,'Manage Portfolios')&&str_contains($agentHtml,'Create a Story'),
    'Unassigned personal Agent renders settings, Stories and downstream workspace controls.');
assert_ui(str_contains($agentHtml,'Team assignment is optional'),
    'Agent explicitly remains independent unless the owner opts into Team sharing.');
assert_ui($a->query('//form[input[@name="op" and @value="assign_team"] and .//select[@name="team_id"] and .//input[@name="csrf"]]')->length===1,
    'Optional Agent-side attach control renders with owned-Team dropdown and CSRF.');
$agentVars['canAttachTeam']=false;$agentVars['agent']['visibility']='public';
$publicHtml=render_real_page('research-agent-edit.php',$agentVars);
assert_ui(str_contains($publicHtml,'</html>')&&str_contains($publicHtml,'Agent settings')&&
          !str_contains($publicHtml,'name="op" value="assign_team"'),
    'Public or otherwise ineligible Agent still renders its normal edit page without forced assignment.');
$agentVars['assignedTeam']=$team+['owner_member'=>1];$agentVars['canAttachTeam']=false;
$assignedHtml=render_real_page('research-agent-edit.php',$agentVars);
assert_ui(str_contains($assignedHtml,'Currently attached to Workola')&&str_contains($assignedHtml,'Remove from Team')&&
          str_contains($assignedHtml,'Manage Portfolios'),
    'Already assigned Agent can unassign and still renders full management page.');
echo "Actual Team and Agent page rendering regression passed.\n";
