<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');if($dsn==='')throw new RuntimeException('DB_DSN required.');
$pdo=new PDO($dsn,(string)getenv('DB_USER'),(string)getenv('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','subscriptions','account-admin','account-membership','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-agent-shell-ui','team-research-resources'] as $lib)require_once $root.'/app/'.$lib.'.php';
function checkTeam(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);echo "PASS: $label\n";}
$suffix=substr(bin2hex(random_bytes(8)),0,12);$public=fn($p)=>$p.'-'.$suffix.'-'.substr(bin2hex(random_bytes(3)),0,6);
$user=function(string $name)use($pdo,$public,$suffix):array{
  $username=strtolower($name).$suffix;
  $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
    ->execute([$public('user'),$username,$name,$username.'@example.test']);
  $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([(int)$pdo->lastInsertId()]);return $q->fetch();
};
$owner=$user('TeamResourceOwner');$admin=$user('TeamResourceAdmin');$researcher=$user('TeamResourceResearcher');$viewer=$user('TeamResourceViewer');$outsider=$user('TeamResourceOutsider');
$teamPublic=$public('team');
$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,(int)$owner['id'],'Team audit workspace']);
$teamId=(int)$pdo->lastInsertId();
foreach([[$owner,'owner'],[$admin,'admin'],[$researcher,'researcher'],[$viewer,'viewer']] as [$member,$role])
    $pdo->prepare('INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,?)')->execute([$teamId,(int)$member['id'],$role]);
$team=['id'=>$teamId,'public_id'=>$teamPublic,'owner_user_id'=>(int)$owner['id'],'access_role'=>'owner'];
$otherTeamPublic=$public('other-team');
$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')
    ->execute([$otherTeamPublic,(int)$admin['id'],'Admin owned only']);
$otherTeamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner')")
    ->execute([$otherTeamId,(int)$admin['id']]);
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'admin')")
    ->execute([$otherTeamId,(int)$owner['id']]);
$ownerTeams=team_research_owned_teams($pdo,(int)$owner['id']);
checkTeam(count($ownerTeams)===1&&(string)$ownerTeams[0]['public_id']===$teamPublic,'Agent Team picker lists only Teams the user owns, never merely administers.');
checkTeam(team_research_owned_team($pdo,(int)$owner['id'],$otherTeamPublic)===null,'Forged selection of a Team the Agent owner only administers is rejected.');
checkTeam(team_research_owned_team($pdo,(int)$owner['id'],$teamPublic)!==null,'Owner can resolve their eligible Team for Agent edit assignment.');

checkTeam(research_agent_team($pdo,$owner,$teamPublic)!==null,'Only the current Team owner may create an Agent directly in the Team.');
checkTeam(research_agent_team($pdo,$admin,$teamPublic)===null,'Team admins cannot bypass owner-only assignment through direct Agent creation.');
checkTeam(research_agent_team($pdo,$researcher,$teamPublic)===null,'Team researchers cannot bypass owner-only assignment through direct Agent creation.');
$projectPublic=$public('project');$agentPublic=$public('agent');$convPublic=$public('conv');
$pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,title,description,status) VALUES(?,?,?,?,'active')")
    ->execute([$projectPublic,(int)$owner['id'],'Team review workspace','Owner controlled workspace']);
$projectId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO conversations(public_id,conversation_type,created_by_user_id,title) VALUES(?,'agent',?,?)")
    ->execute([$convPublic,(int)$owner['id'],'Team audit agent']);
$conversationId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO conversation_members(conversation_id,user_id,member_role) VALUES(?,?,'owner')")->execute([$conversationId,(int)$owner['id']]);
$pdo->prepare("INSERT INTO conversation_members(conversation_id,user_id,member_role) VALUES(?,?,'member')")->execute([$conversationId,(int)$outsider['id']]);
$pdo->prepare("INSERT INTO research_agents(public_id,owner_user_id,project_id,conversation_id,name,visibility,status,monitoring_cadence) VALUES(?,?,?,?,?,'private','active','manual')")
    ->execute([$agentPublic,(int)$owner['id'],$projectId,$conversationId,'Team resource audit agent']);
$assignable=team_research_assignable($pdo,(int)$owner['id']);
checkTeam(in_array($agentPublic,array_column($assignable,'public_id'),true),'Only unassigned personally owned Agents are offered.');
$denied=false;
try{team_research_assign($pdo,array_merge($team,['access_role'=>'admin']),$admin,$agentPublic);}catch(RuntimeException $e){$denied=true;}
checkTeam($denied,'Team admin cannot assign the Team owner’s Research Agent.');
$forged=['id'=>$teamId,'owner_user_id'=>(int)$outsider['id'],'access_role'=>'owner'];$denied=false;
try{team_research_assign($pdo,$forged,$outsider,$agentPublic);}catch(RuntimeException $e){$denied=true;}
checkTeam($denied,'Forged owner context cannot assign a different user’s Agent.');
team_research_assign($pdo,$team,$owner,$agentPublic);
$attached=team_research_agent_team($pdo,(int)$owner['id'],$agentPublic);
checkTeam($attached!==null&&(string)$attached['public_id']===$teamPublic&&!empty($attached['owner_member']),'Agent edit page resolves the current Team and verifies owner membership.');
$shared=team_research_resources($pdo,$teamId);
checkTeam(count($shared)===1&&(string)$shared[0]['conversation_public_id']===$convPublic,'Shared Team resource includes canonical Agent conversation.');
checkTeam(str_contains(research_agent_shell_href($shared[0],'chat'),'/home.php?agent='),
    'Team Agent Chat opens the selected canonical conversation.');
checkTeam(str_contains(research_agent_shell_href($shared[0],'desktop'),'workspace=desktop')&&
    str_contains(research_agent_shell_href($shared[0],'library'),'workspace=library'),
    'Team Desktop and Library open the selected Agent workspace.');
checkTeam(str_contains(research_agent_shell_href($shared[0],'reports'),'/research-reports.php?agent='),
    'Team Report Studio remains linked to the selected Agent.');
checkTeam((int)$pdo->query('SELECT team_id FROM research_agents WHERE public_id='.$pdo->quote($agentPublic))->fetchColumn()===$teamId,'Agent uses canonical Team assignment.');
checkTeam((int)$pdo->query('SELECT team_id FROM research_projects WHERE public_id='.$pdo->quote($projectPublic))->fetchColumn()===$teamId,'Desktop and Library use canonical Team Project.');
$q=$pdo->prepare('SELECT user_id,member_role FROM conversation_members WHERE conversation_id=?');$q->execute([$conversationId]);$members=[];foreach($q->fetchAll() as $m)$members[(int)$m['user_id']]=$m['member_role'];
checkTeam(!isset($members[(int)$outsider['id']])&&$members[(int)$admin['id']]==='admin'&&$members[(int)$researcher['id']]==='member'&&$members[(int)$viewer['id']]==='member','Assignment revokes outsider invites and syncs team conversation members.');
checkTeam(research_agent_access($pdo,$researcher,$agentPublic)!==null,'Researcher can open the Team Agent.');
$adminEditDenied=false;try{research_agent_update_profile($pdo,$admin,$agentPublic,['name'=>'Unauthorized Agent Rename']);}catch(RuntimeException $e){$adminEditDenied=true;}
checkTeam($adminEditDenied,'Team admins cannot modify Agent profile or settings owned by the Team owner.');
$publicAfterShareDenied=false;try{research_agent_update_profile($pdo,$owner,$agentPublic,['visibility'=>'public']);}catch(RuntimeException $e){$publicAfterShareDenied=true;}
checkTeam($publicAfterShareDenied,'Team-shared Agent cannot be made public after assignment.');
$adminContext=research_agent_shell_resolve($pdo,$admin,$agentPublic,[research_agent_access($pdo,$admin,$agentPublic)]);
$ownerContext=research_agent_shell_resolve($pdo,$owner,$agentPublic,[research_agent_access($pdo,$owner,$agentPublic)]);
checkTeam(empty($adminContext['agent']['can_edit'])&&!empty($ownerContext['agent']['can_edit']),'Agent shell exposes owner-only editing capability.');
checkTeam(research_agent_workspace_project($pdo,$viewer,$agentPublic)!==null,'Viewer can access the Team Desktop and Library.');
$viewerWriteDenied=false;
try{research_agent_workspace_require_write(research_agent_workspace_project($pdo,$viewer,$agentPublic));}catch(RuntimeException $e){$viewerWriteDenied=true;}
checkTeam($viewerWriteDenied,'Team viewer cannot modify the shared workspace.');
research_agent_workspace_require_write(research_agent_workspace_project($pdo,$researcher,$agentPublic));
echo "PASS: Team researcher can work in the shared workspace.\n";
$pdo->prepare("UPDATE team_members SET role='viewer' WHERE team_id=? AND user_id=?")->execute([$teamId,(int)$researcher['id']]);
team_research_sync_member($pdo,$teamId,(int)$researcher['id'],true);
$role=$pdo->prepare('SELECT member_role FROM conversation_members WHERE conversation_id=? AND user_id=?');
$role->execute([$conversationId,(int)$researcher['id']]);checkTeam($role->fetchColumn()==='member','Role changes preserve canonical conversation membership.');
$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,(int)$researcher['id']]);
team_research_sync_member($pdo,$teamId,(int)$researcher['id'],false);
checkTeam(research_agent_access($pdo,$researcher,$agentPublic)===null,'Removed team members immediately lose Agent access.');
$denied=false;
try{team_research_unassign($pdo,$team,$admin,$agentPublic);}catch(RuntimeException $e){$denied=true;}
checkTeam($denied,'Team admin cannot remove the owner’s assigned Agent.');
team_research_unassign($pdo,$team,$owner,$agentPublic);
checkTeam(team_research_agent_team($pdo,(int)$owner['id'],$agentPublic)===null,'Removing Team assignment updates the Agent edit page back to unassigned.');
checkTeam(research_agent_access($pdo,$viewer,$agentPublic)===null,'Unassignment revokes Team access.');
$q=$pdo->prepare('SELECT user_id FROM conversation_members WHERE conversation_id=?');$q->execute([$conversationId]);
checkTeam(array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN))===[(int)$owner['id']],'Original owner is sole conversation member after unassignment.');
checkTeam(in_array($agentPublic,array_column(team_research_assignable($pdo,(int)$owner['id']),'public_id'),true),'Agent can be re-shared only after returning to private ownership.');
$pdo->prepare("UPDATE research_agents SET visibility='public' WHERE public_id=?")->execute([$agentPublic]);
checkTeam(!in_array($agentPublic,array_column(team_research_assignable($pdo,(int)$owner['id']),'public_id'),true),'Public Agents cannot be selected for Team sharing.');
$publicDenied=false;try{team_research_assign($pdo,$team,$owner,$agentPublic);}catch(RuntimeException $e){$publicDenied=true;}
checkTeam($publicDenied,'Runtime rejects sharing a public Agent into a private Team.');
echo "Team Research resources database journey passed.\n";
