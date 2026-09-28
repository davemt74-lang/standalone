<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-missions','research-decisions','research-action-plans','research-action-plan-variance','research-action-plan-cognition','research-action-plan-outcomes'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function p73s1(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function p73s1throws(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}

p73s1(research_intelligence_portfolio_native_decisions_ready($pdo),'Migration 099 exposes the native Portfolio Decision bridge.');
$run='p73s1'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{
 $username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
 ->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('Phase73Owner');$collab=$makeUser('Phase73Collaborator');$outsider=$makeUser('Phase73Outsider');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,(int)$owner['id'],'Phase 73 Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,(int)$owner['id'],$teamId,(int)$collab['id']]);

$agent=research_agent_create($pdo,$owner,['name'=>'Phase 73 Portfolio Agent','description'=>'Portfolio-native Decision fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$program=research_program_create($pdo,$owner,['agent_id'=>$agent['public_id'],'title'=>'Phase 73 Portfolio Program','objective'=>'Track portfolio evidence for executive decisions.','cadence'=>'manual','priority'=>'high','tasks'=>[['title'=>'Monitor portfolio evidence','task_type'=>'general']]]);
$portfolio=research_intelligence_portfolio_create($pdo,$owner,['title'=>'Phase 73 Executive Portfolio','objective'=>'Turn cross-program intelligence into governed native Decisions.','team_id'=>$teamPublic,'briefing_cadence'=>'manual','timezone_name'=>'UTC']);
$portfolio=research_intelligence_portfolio_add_program($pdo,$owner,(string)$portfolio['public_id'],(string)$program['public_id'],'primary');
p73s1((int)$portfolio['anchor_program_id']===(int)$program['id'],'Portfolio primary Program is the native Decision anchor.');

$snapshot=research_intelligence_portfolio_snapshot($pdo,$owner,(string)$portfolio['public_id'],30,'manual');
$insight=research_intelligence_portfolio_add_inference($pdo,$owner,(string)$portfolio['public_id'],[
 'title'=>'Portfolio expansion risk','body'=>'Cross-program evidence indicates expansion should be explicitly decided before execution.',
 'category'=>'decision','severity'=>'high','confidence'=>0.88,'snapshot_id'=>$snapshot['public_id'],
 'provenance_refs'=>[['type'=>'program','id'=>(string)$program['public_id'],'label'=>'Anchor Program']]
],false);
$briefing=research_intelligence_portfolio_create_briefing($pdo,$owner,(string)$portfolio['public_id'],['snapshot_public_id'=>$snapshot['public_id'],'title'=>'Phase 73 Executive Briefing']);
$beforeOutcome=(int)$pdo->query("SELECT COUNT(*) FROM research_outcome_events WHERE event_type='portfolio_decision'")->fetchColumn();
$beforeTasks=(int)$pdo->query('SELECT COUNT(*) FROM research_tasks')->fetchColumn();

$input=[
 'idempotency_key'=>'phase73-native-decision-one','title'=>'Gate portfolio expansion','decision_type'=>'decision',
 'statement'=>'Do not expand the portfolio program until the identified risk is explicitly addressed.',
 'rationale'=>'The Executive Briefing and high-severity Portfolio insight show material unresolved risk.','confidence'=>'0.88',
 'insight_id'=>(string)$insight['public_id'],'briefing_id'=>(string)$briefing['public_id']
];
$result=research_intelligence_portfolio_create_native_decision($pdo,$owner,(string)$portfolio['public_id'],$input);$decision=$result['decision'];
p73s1(($decision['status']??'')==='draft'&&($decision['decision_type']??'')==='decision','New Portfolio handoff creates a real Draft Phase 71 Decision.');
p73s1(($decision['agent_public_id']??'')===(string)$agent['public_id']&&($decision['project_id']??0)===(int)$program['project_id'],'Native Decision is anchored to the Portfolio anchor Program Research Agent/project.');

$q=$pdo->prepare('SELECT * FROM research_intelligence_portfolio_decision_links WHERE portfolio_id=? AND decision_id=? LIMIT 1');$q->execute([(int)$portfolio['id'],(int)$decision['id']]);$link=$q->fetch();
p73s1($link&&$link['outcome_id']===null&&$link['task_id']===null&&preg_match('/^[a-f0-9]{64}$/',(string)$link['handoff_key']),'Native handoff stores Decision lineage without fabricating legacy Outcome Learning or a Task.');
p73s1((int)$pdo->query("SELECT COUNT(*) FROM research_outcome_events WHERE event_type='portfolio_decision'")->fetchColumn()===$beforeOutcome,'New native handoff creates no Phase 61 portfolio_decision outcome event.');
p73s1((int)$pdo->query('SELECT COUNT(*) FROM research_tasks')->fetchColumn()===$beforeTasks,'New native handoff creates no direct follow-up Research Task.');

$refTypes=array_column((array)$decision['refs'],'ref_type');sort($refTypes);
p73s1(in_array('portfolio',$refTypes,true)&&in_array('portfolio_insight',$refTypes,true)&&in_array('executive_briefing',$refTypes,true),'Decision carries permission-checked Portfolio, insight, and Executive Briefing provenance.');

$retry=research_intelligence_portfolio_create_native_decision($pdo,$owner,(string)$portfolio['public_id'],array_merge($input,['title'=>'Retry must not overwrite']));
p73s1(($retry['decision']['public_id']??'')===(string)$decision['public_id']&&($retry['decision']['title']??'')==='Gate portfolio expansion','Portfolio native Decision handoff is idempotent and retries cannot overwrite the original Decision.');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_intelligence_portfolio_decision_links WHERE portfolio_id=? AND decision_id IS NOT NULL');$q->execute([(int)$portfolio['id']]);p73s1((int)$q->fetchColumn()===1,'Idempotent retry leaves one native Portfolio Decision link.');

$rows=research_intelligence_portfolio_decision_rows($pdo,$owner,$portfolio,20);$native=null;foreach($rows as $row)if(($row['decision_public_id']??'')===(string)$decision['public_id']){$native=$row;break;}
p73s1($native!==null&&($native['record_kind']??'')==='native'&&($native['status']??'')==='draft','Portfolio decision list projects native Decision status rather than legacy outcome labels.');

p73s1(research_decision_access($pdo,$collab,(string)$decision['public_id'])!==null,'Current Team collaborator can access the native Portfolio Decision.');
p73s1(research_decision_access($pdo,$outsider,(string)$decision['public_id'])===null,'Outsider cannot access the Portfolio-origin Decision.');

$accepted=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'accepted');
$plan=research_action_plan_from_decision($pdo,$owner,(string)$accepted['public_id'],[
 'idempotency_key'=>'phase73-portfolio-decision-action-plan','title'=>'Execute gated portfolio expansion',
 'objective'=>'Address the accepted Portfolio Decision through the normal governed Action Plan path.',
 'expected_result'=>'Risk is addressed before expansion.','success_measures'=>[['label'=>'Risk gate','target'=>'Resolved before expansion']]
]);
p73s1(($accepted['status']??'')==='accepted'&&($plan['status']??'')==='draft','Accepted Portfolio-origin Decision enters the existing Phase 72 Action Plan handoff with no Portfolio-specific execution engine.');

p73s1throws(fn()=>research_intelligence_portfolio_create_native_decision($pdo,$owner,(string)$portfolio['public_id'],array_merge($input,['idempotency_key'=>'phase73-task-shortcut','create_follow_up'=>true])),'Native Portfolio Decision entry rejects the old direct follow-up Task shortcut.');

$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,(int)$collab['id']]);
p73s1(research_decision_access($pdo,$collab,(string)$decision['public_id'])===null,'Team revocation immediately removes native Portfolio Decision access.');

echo "Phase 73 Section 1 Portfolio → Native Decision Handoff database journey passed.\n";
