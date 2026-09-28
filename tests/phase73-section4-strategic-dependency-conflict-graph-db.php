<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-missions','research-decisions','research-action-plans','research-action-plan-variance','research-action-plan-cognition','research-action-plan-outcomes','research-intelligence-decision-rollups','research-intelligence-pattern-memory','research-intelligence-strategic-graph'] as $lib)require_once $root.'/app/'.$lib.'.php';
function p73s4(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function p73s4throws(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}

p73s4(research_intelligence_strategic_graph_ready($pdo),'Migration 101 exposes the strategic graph without replacing Decision/Action Plan stores.');
$run='p73s4'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('Phase73S4Owner');$collab=$makeUser('Phase73S4Collaborator');$outsider=$makeUser('Phase73S4Outsider');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Phase 73 Section 4 Team']);$teamId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$collab['id']]);

$agentA=research_agent_create($pdo,$owner,['name'=>'Phase 73 S4 Agent A','description'=>'Strategic graph fixture A','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$agentB=research_agent_create($pdo,$owner,['name'=>'Phase 73 S4 Agent B','description'=>'Strategic graph fixture B','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$programA=research_program_create($pdo,$owner,['agent_id'=>$agentA['public_id'],'title'=>'Phase 73 S4 Program A','objective'=>'Portfolio A graph nodes.','cadence'=>'manual','tasks'=>[['title'=>'Monitor A','task_type'=>'general']]]);
$programB=research_program_create($pdo,$owner,['agent_id'=>$agentB['public_id'],'title'=>'Phase 73 S4 Program B','objective'=>'Portfolio B graph nodes.','cadence'=>'manual','tasks'=>[['title'=>'Monitor B','task_type'=>'general']]]);
$portfolioA=research_intelligence_portfolio_create($pdo,$owner,['title'=>'Phase 73 S4 Portfolio A','objective'=>'Source strategic relationships.','team_id'=>$teamPublic,'briefing_cadence'=>'manual','timezone_name'=>'UTC']);
$portfolioB=research_intelligence_portfolio_create($pdo,$owner,['title'=>'Phase 73 S4 Portfolio B','objective'=>'Cross-Portfolio strategic targets.','team_id'=>$teamPublic,'briefing_cadence'=>'manual','timezone_name'=>'UTC']);
$portfolioA=research_intelligence_portfolio_add_program($pdo,$owner,(string)$portfolioA['public_id'],(string)$programA['public_id'],'primary');
$portfolioB=research_intelligence_portfolio_add_program($pdo,$owner,(string)$portfolioB['public_id'],(string)$programB['public_id'],'primary');

$makeDecision=function(array $portfolio,string $key,string $title)use($pdo,$owner): array{
 $native=research_intelligence_portfolio_create_native_decision($pdo,$owner,(string)$portfolio['public_id'],['idempotency_key'=>$key,'title'=>$title,'statement'=>'Governed strategic statement for '.$title.'.','rationale'=>'Explicit rationale for graph testing.','decision_type'=>'decision','confidence'=>0.9]);
 return research_decision_set_status($pdo,$owner,(string)$native['decision']['public_id'],'accepted');
};
$a=$makeDecision($portfolioA,'s4-a','Decision A');
$b=$makeDecision($portfolioB,'s4-b','Decision B');
$c=$makeDecision($portfolioA,'s4-c','Decision C');
$nonPortfolio=research_decision_create($pdo,$owner,['agent_id'=>$agentA['public_id'],'title'=>'Non-Portfolio Decision','statement'=>'Accessible Decision without Portfolio lineage.','rationale'=>'Negative-control graph target.']);
research_decision_add_challenge($pdo,$owner,(string)$a['public_id'],['challenge_type'=>'contradiction','title'=>'High contradiction','detail'=>'Existing Phase 71 challenge remains node metadata.','severity'=>'high']);
research_decision_add_challenge($pdo,$owner,(string)$a['public_id'],['challenge_type'=>'reversal_condition','title'=>'Reversal condition','detail'=>'Existing reversal condition remains node metadata.','severity'=>'medium']);
$a=research_decision_detail($pdo,$owner,(string)$a['public_id']);
$planA=research_action_plan_from_decision($pdo,$owner,(string)$a['public_id'],['idempotency_key'=>'s4-plan-a','title'=>'Plan A','objective'=>'Execute A.','expected_result'=>'A result.','success_measures'=>[['label'=>'A','target'=>'Done']]]);
$planB=research_action_plan_from_decision($pdo,$owner,(string)$b['public_id'],['idempotency_key'=>'s4-plan-b','title'=>'Plan B','objective'=>'Execute B.','expected_result'=>'B result.','success_measures'=>[['label'=>'B','target'=>'Done']]]);

$sourceState=['a'=>(string)$a['status'],'a_rev'=>(int)$a['current_revision'],'b'=>(string)$b['status'],'b_rev'=>(int)$b['current_revision'],'c'=>(string)$c['status'],'c_rev'=>(int)$c['current_revision'],'planA'=>(string)$planA['status'],'planA_rev'=>(int)$planA['current_revision'],'planB'=>(string)$planB['status'],'planB_rev'=>(int)$planB['current_revision']];
$edge=function(array $portfolio,string $st,string $sid,string $tt,string $tid,string $relation,string $why,string $materiality='medium')use($pdo,$owner){return research_intelligence_strategic_edge_upsert($pdo,$owner,(string)$portfolio['public_id'],['source_type'=>$st,'source_public_id'=>$sid,'target_type'=>$tt,'target_public_id'=>$tid,'relation_type'=>$relation,'rationale'=>$why,'confidence'=>0.85,'materiality'=>$materiality]);};

$conflict=$edge($portfolioA,'decision',(string)$a['public_id'],'decision',(string)$b['public_id'],'conflicts_with','Decision A conflicts with Decision B across Portfolio boundaries.','critical');
p73s4($conflict['active']&&$conflict['cross_portfolio'],'Cross-Portfolio conflict edge is explicit, active, and permission checked.');
$reverse=$edge($portfolioB,'decision',(string)$b['public_id'],'decision',(string)$a['public_id'],'conflicts_with','Reverse write must de-duplicate.');
p73s4((string)$reverse['public_id']===(string)$conflict['public_id'],'Symmetric conflict reverse write de-duplicates to the same edge.');

$support=$edge($portfolioA,'decision',(string)$a['public_id'],'action_plan',(string)$planA['public_id'],'supports','Decision A supports its execution Plan.');
$block=$edge($portfolioA,'action_plan',(string)$planA['public_id'],'decision',(string)$b['public_id'],'blocks','Plan A blocks Decision B until completion.','high');
$dependency=$edge($portfolioA,'decision',(string)$a['public_id'],'decision',(string)$b['public_id'],'depends_on','Decision A depends on Decision B constraints.');
p73s4((int)$dependency['source_revision']===(int)$a['current_revision']&&(int)$dependency['target_revision']===(int)$b['current_revision'],'Directional relationship freezes exact source and target revisions alongside state hashes.');
$duplicate=$edge($portfolioA,'decision',(string)$a['public_id'],'decision',(string)$c['public_id'],'duplicates','Decision A and C duplicate strategic scope.');
$duplicateReverse=$edge($portfolioA,'decision',(string)$c['public_id'],'decision',(string)$a['public_id'],'duplicates','Reverse duplicate should reuse.');
p73s4((string)$duplicateReverse['public_id']===(string)$duplicate['public_id'],'Symmetric duplicate reverse write de-duplicates.');
$supersedes=$edge($portfolioA,'decision',(string)$a['public_id'],'decision',(string)$c['public_id'],'supersedes','Decision A supersedes Decision C directionally.');
$affects=$edge($portfolioA,'action_plan',(string)$planA['public_id'],'action_plan',(string)$planB['public_id'],'materially_affects','Plan A materially affects Plan B capacity.','high');

p73s4throws(fn()=>$edge($portfolioB,'decision',(string)$b['public_id'],'decision',(string)$a['public_id'],'depends_on','Would create dependency cycle.'),'Dependency cycle is rejected before write.');
p73s4throws(fn()=>$edge($portfolioB,'decision',(string)$b['public_id'],'action_plan',(string)$planA['public_id'],'blocks','Would create blocking cycle.'),'Blocking cycle is rejected before write.');
p73s4throws(fn()=>$edge($portfolioA,'decision',(string)$c['public_id'],'decision',(string)$a['public_id'],'supersedes','Would create supersession cycle.'),'Supersession cycle is rejected before write.');
p73s4throws(fn()=>$edge($portfolioA,'decision',(string)$a['public_id'],'decision',(string)$a['public_id'],'supports','Self edge invalid.'),'Self relationship is rejected.');
p73s4throws(fn()=>$edge($portfolioA,'decision',(string)$b['public_id'],'decision',(string)$a['public_id'],'supports','Wrong source Portfolio.'),'A graph source must belong to the Portfolio recording the edge.');
p73s4throws(fn()=>$edge($portfolioA,'decision',(string)$a['public_id'],'action_plan',(string)$planB['public_id'],'duplicates','Cross-type duplicate invalid.'),'Duplicate relationship cannot connect unlike strategic object types.');
p73s4throws(fn()=>$edge($portfolioA,'decision',(string)$a['public_id'],'decision',(string)$nonPortfolio['public_id'],'supports','Non-Portfolio target invalid.'),'Strategic graph target must belong to an accessible Intelligence Portfolio.');

$aNow=research_decision_detail($pdo,$owner,(string)$a['public_id']);$bNow=research_decision_detail($pdo,$owner,(string)$b['public_id']);$cNow=research_decision_detail($pdo,$owner,(string)$c['public_id']);$paNow=research_action_plan_detail($pdo,$owner,(string)$planA['public_id']);$pbNow=research_action_plan_detail($pdo,$owner,(string)$planB['public_id']);
p73s4($sourceState===['a'=>(string)$aNow['status'],'a_rev'=>(int)$aNow['current_revision'],'b'=>(string)$bNow['status'],'b_rev'=>(int)$bNow['current_revision'],'c'=>(string)$cNow['status'],'c_rev'=>(int)$cNow['current_revision'],'planA'=>(string)$paNow['status'],'planA_rev'=>(int)$paNow['current_revision'],'planB'=>(string)$pbNow['status'],'planB_rev'=>(int)$pbNow['current_revision']],'Graph writes do not mutate Decision or Action Plan lifecycle or revision state.');

$graphA=research_intelligence_portfolio_strategic_graph($pdo,$owner,(string)$portfolioA['public_id'],false,300);
p73s4($graphA['summary']['active_edges']===7,'All seven supported relationship types can coexist as explicit graph edges.');
p73s4($graphA['summary']['conflicts']===1&&$graphA['summary']['blocks']===1&&$graphA['summary']['dependencies']===1&&$graphA['summary']['cross_portfolio_edges']>=4,'Portfolio graph summarizes conflict, block, dependency, and cross-Portfolio relationships.');
p73s4(($graphA['summary']['high_or_critical_edges']??0)>=3&&($graphA['summary']['high_or_critical_conflicts']??0)===1,'Graph summary preserves explicit materiality for executive attention.');
p73s4(($graphA['summary']['open_decision_challenges']??0)>=2&&($graphA['summary']['high_open_decision_challenges']??0)>=1&&($graphA['summary']['reversal_conditions']??0)>=1,'Graph reuses existing Phase 71 challenge/reversal state as Decision-node metadata.');
$externalB=array_values(array_filter((array)$graphA['nodes'],fn($n)=>(string)($n['public_id']??'')===(string)$b['public_id']));
p73s4(count($externalB)===1&&empty($externalB[0]['in_portfolio']),'Cross-Portfolio relationship includes its external endpoint node instead of returning a dangling edge.');
$initialConflictAttention=array_values(array_filter((array)$graphA['attention'],fn($x)=>(string)$x['edge_id']===(string)$conflict['public_id']));
p73s4(count($initialConflictAttention)===1&&in_array('conflict',(array)$initialConflictAttention[0]['reasons'],true)&&in_array('high_materiality',(array)$initialConflictAttention[0]['reasons'],true),'Critical conflict carries deterministic conflict and high-materiality attention reasons.');

$b=research_decision_set_status($pdo,$owner,(string)$b['public_id'],'reopened');
$staleConflict=research_intelligence_strategic_edge_access($pdo,$owner,(string)$conflict['public_id']);
p73s4(!empty($staleConflict['stale'])&&(!empty($staleConflict['source_stale'])||!empty($staleConflict['target_stale'])),'Decision lifecycle change makes saved relationship provenance stale.');
$afterReopenGraph=research_intelligence_portfolio_strategic_graph($pdo,$owner,(string)$portfolioA['public_id'],false,300);
p73s4(($afterReopenGraph['summary']['unresolved_dependencies']??0)===1,'Reopened dependency target is surfaced as unresolved without changing either source object.');
$depAttention=array_values(array_filter((array)$afterReopenGraph['attention'],fn($x)=>(string)$x['edge_id']===(string)$dependency['public_id']));
p73s4(count($depAttention)===1&&in_array('dependency_unresolved',(array)$depAttention[0]['reasons'],true)&&in_array('stale_relationship',(array)$depAttention[0]['reasons'],true),'Dependency attention explains both unresolved target state and stale saved provenance.');
$refreshed=research_intelligence_strategic_edge_refresh($pdo,$owner,(string)$conflict['public_id']);
p73s4(empty($refreshed['stale'])&&max((int)$refreshed['source_revision'],(int)$refreshed['target_revision'])>=2,'Explicit acknowledgement refreshes relationship endpoint revisions and state hashes.');

p73s4throws(fn()=>research_intelligence_strategic_edge_remove($pdo,$owner,(string)$support['public_id'],''),'Removing a strategic relationship requires an explicit audited reason.');
$removed=research_intelligence_strategic_edge_remove($pdo,$owner,(string)$support['public_id'],'Execution support relationship is no longer operationally relevant.');
p73s4(empty($removed['active'])&&(string)($removed['removal_reason']??'')==='Execution support relationship is no longer operationally relevant.','Removing a relationship retains inactive history plus the explicit removal reason.');
$restored=$edge($portfolioA,'decision',(string)$a['public_id'],'action_plan',(string)$planA['public_id'],'supports','Restored support relationship.');
p73s4((string)$restored['public_id']===(string)$support['public_id']&&!empty($restored['active']),'Re-recording a removed relationship restores the same edge.');
$events=research_intelligence_strategic_edge_events($pdo,$owner,(string)$support['public_id'],20);$types=array_column($events,'event_type');
p73s4(in_array('created',$types,true)&&in_array('removed',$types,true)&&in_array('restored',$types,true),'Relationship audit history retains created, removed, and restored events.');
$removedEvents=array_values(array_filter($events,fn($e)=>(string)$e['event_type']==='removed'));
p73s4(count($removedEvents)===1&&($removedEvents[0]['snapshot']['removal_reason']??'')==='Execution support relationship is no longer operationally relevant.','Removal audit event freezes the explicit removal reason.');

$org=research_intelligence_organization_strategic_graph($pdo,$owner);
p73s4($org['summary']['active_edges']===7&&$org['summary']['conflicts']===1&&$org['summary']['blocks']===1,'Organization graph de-duplicates relationships visible in multiple Portfolios.');
$attention=array_values(array_filter($org['attention'],fn($x)=>in_array((string)$x['relation_type'],['conflicts_with','blocks'],true)));
p73s4(count($attention)>=2,'Organization Command Center attention includes explicit conflicts and blockers.');
$conflictOrg=array_values(array_filter($attention,fn($x)=>(string)$x['edge_id']===(string)$conflict['public_id']));
$blockOrg=array_values(array_filter($attention,fn($x)=>(string)$x['edge_id']===(string)$block['public_id']));
p73s4(count($conflictOrg)===1&&($conflictOrg[0]['materiality']??'')==='critical'&&in_array('conflict',(array)$conflictOrg[0]['reasons'],true),'Organization attention preserves critical conflict materiality and reason.');
p73s4(count($blockOrg)===1&&($blockOrg[0]['materiality']??'')==='high'&&in_array('blocking_relationship',(array)$blockOrg[0]['reasons'],true),'Organization attention preserves high blocking materiality and reason.');
$feed=[];research_intelligence_portfolio_operations_cognitive_observations($pdo,$owner,$feed,20);
$graphFeed=array_values(array_filter($feed,fn($item)=>(string)($item['type']??'')==='portfolio_strategic_relationship'));
p73s4(count($graphFeed)>=2,'Existing Now/cognitive feed surfaces strategic graph attention without changing source state.');

p73s4(research_intelligence_strategic_edge_access($pdo,$outsider,(string)$conflict['public_id'])===null,'Outsider cannot access strategic graph relationship.');
p73s4throws(fn()=>research_intelligence_strategic_edge_upsert($pdo,$outsider,(string)$portfolioA['public_id'],['source_type'=>'decision','source_public_id'=>$a['public_id'],'target_type'=>'decision','target_public_id'=>$b['public_id'],'relation_type'=>'supports','rationale'=>'Denied']),'Outsider cannot write strategic graph relationship.');
p73s4(research_intelligence_portfolio_strategic_graph($pdo,$collab,(string)$portfolioA['public_id'])['ready'],'Current Team collaborator can inspect strategic graph.');
$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$collab['id']]);
p73s4throws(fn()=>research_intelligence_portfolio_strategic_graph($pdo,$collab,(string)$portfolioA['public_id']),'Team revocation immediately removes Portfolio graph access.');
p73s4(research_intelligence_strategic_edge_access($pdo,$collab,(string)$conflict['public_id'])===null,'Team revocation also removes individual edge access.');

$runtime=(string)file_get_contents($root.'/app/research-intelligence-strategic-graph.php');
p73s4(!str_contains($runtime,'research_decision_set_status')&&!str_contains($runtime,'research_action_plan_set_status')&&!str_contains($runtime,'ai_run('),'Strategic graph has no source-state or AI mutation authority.');
echo "Phase 73 Section 4 Strategic Dependency & Conflict Graph database journey passed.\n";
