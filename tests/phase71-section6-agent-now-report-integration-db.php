<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','cognitive-feed','research-tasks','research-programs','research-missions','cross-research','research-outcomes','research-decisions'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function p71s6(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}

p71s6(research_decisions_ready($pdo)&&research_decision_reconsiderations_ready($pdo),'Decision Memory through Section 5 is ready for Section 6 integration.');
$run='p71s6'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$username='p71s6_'.$run;
$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
 ->execute([$pub('u'),$username,'Phase 71 S6',$username.'@example.test']);
$userId=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$userId]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$userId]);$owner=$q->fetch();

$agent=research_agent_create($pdo,$owner,['name'=>'Decision Integration Agent','description'=>'Phase 71 Section 6 fixture','cadence'=>'manual','timezone_name'=>'UTC']);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);

$sources=[];
foreach([1,2] as $n){$url='https://8.8.8.8/'.$run.'/integration-source-'.$n;$source=ensure_source($pdo,$url,'Integration Source '.$n);$body='Decision integration evidence '.$n.' '.$run;
  $pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,captured_at) VALUES(?,1,?,?,?,?,NOW())")->execute([(int)$source['id'],$url,'Integration Source '.$n,$body,hash('sha256',$body)]);
  $sv=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=?,last_checked_at=NOW() WHERE id=?')->execute([$sv,(int)$source['id']]);
  $pdo->prepare('INSERT IGNORE INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([(int)$project['id'],(int)$source['id'],(int)$owner['id']]);$sources[]=$source;
}

$accepted=research_decision_create($pdo,$owner,[
 'agent_id'=>$agent['public_id'],'decision_type'=>'decision','title'=>'Controlled expansion','statement'=>'Expand through a controlled cohort.',
 'rationale'=>'Current evidence supports bounded expansion while maintaining an operational review gate.','confidence'=>0.84,
 'refs'=>[
  ['type'=>'source','id'=>$sources[0]['public_id'],'role'=>'supports','strength'=>0.9,'note'=>'Internal support note'],
  ['type'=>'source','id'=>$sources[1]['public_id'],'role'=>'contradicts','strength'=>0.8,'note'=>'Internal contradiction note']
 ]
]);
$accepted=research_decision_set_status($pdo,$owner,(string)$accepted['public_id'],'proposed');$accepted=research_decision_set_status($pdo,$owner,(string)$accepted['public_id'],'accepted');
$reversal=research_decision_add_challenge($pdo,$owner,(string)$accepted['public_id'],[
 'challenge_type'=>'reversal_condition','title'=>'Support burden exceeds ceiling','detail'=>'Reconsider if support incidents stay above the operating ceiling.','severity'=>'critical',
 'refs'=>[['type'=>'source','id'=>$sources[1]['public_id'],'role'=>'supports_challenge','strength'=>0.8]]
]);
$outcome=research_decision_record_outcome($pdo,$owner,(string)$accepted['public_id'],[
 'idempotency_key'=>'section6-window-1','assessment'=>'partial',
 'expected_summary'=>'Demand grows while support burden remains within ceiling.',
 'actual_summary'=>'Demand grew, but support burden exceeded the ceiling in the first review window.',
 'variance_summary'=>'Operational burden was worse than expected.','lessons'=>'Support capacity must gate expansion.',
 'confidence'=>0.92,'follow_up_state'=>'follow_up','refs'=>[['type'=>'source','id'=>$sources[1]['public_id'],'role'=>'result']]
]);
$case=research_decision_open_reconsideration($pdo,$owner,(string)$accepted['public_id'],[
 'trigger_type'=>'reversal_condition','trigger_public_id'=>$reversal['public_id'],'title'=>'Review controlled expansion',
 'reason'=>'The explicit reversal condition is now material.','materiality'=>'critical'
]);

$draft=research_decision_create($pdo,$owner,['agent_id'=>$agent['public_id'],'decision_type'=>'recommendation','title'=>'Internal draft option','statement'=>'Draft-only option.','rationale'=>'Not ready for publication.']);
$rejected=research_decision_create($pdo,$owner,['agent_id'=>$agent['public_id'],'decision_type'=>'decision','title'=>'Rejected alternative','statement'=>'Use full expansion immediately.','rationale'=>'This was considered but rejected.']);
$rejected=research_decision_set_status($pdo,$owner,(string)$rejected['public_id'],'rejected');

$before=research_decision_detail($pdo,$owner,(string)$accepted['public_id']);$beforeStatus=(string)$before['status'];$beforeRevision=(int)$before['current_revision'];

$ctx=research_decision_agent_context($pdo,$owner,(string)$agent['public_id'],8);
p71s6(str_contains((string)$ctx['text'],'[DECISION MEMORY]')&&str_contains((string)$ctx['text'],'Controlled expansion'),'Agent context includes durable Decision Memory.');
p71s6(str_contains((string)$ctx['text'],'Rationale: Current evidence supports bounded expansion')&&str_contains((string)$ctx['text'],'Latest observed outcome: PARTIAL'),'Agent context includes rationale and latest observed outcome.');
p71s6(str_contains((string)$ctx['text'],'Review signals:')&&str_contains((string)$ctx['text'],'critical'),'Agent context exposes deterministic review signals without deciding for the user.');
$refKeys=array_map(fn($r)=>(string)$r['type'].'|'.(string)$r['id'],(array)$ctx['refs']);
p71s6(in_array('source|'.$sources[0]['public_id'],$refKeys,true)&&in_array('source|'.$sources[1]['public_id'],$refKeys,true),'Agent Decision context carries underlying provenance references.');

$items=[];research_decision_cognitive_observations($pdo,$owner,$items,20);
$decisionItems=array_values(array_filter($items,fn($item)=>(string)($item['type']??'')==='decision_reconsideration'));
p71s6(count($decisionItems)>=1,'Now/Cognitive Feed receives a Decision-needs-review observation.');
$feed=$decisionItems[0];p71s6(($feed['section']??'')==='needs_attention'&&($feed['priority']??'')==='critical','Critical reversal condition surfaces in Now as needs-attention.');
$actionTypes=array_column((array)($feed['actions']??[]),'type');p71s6(in_array('link',$actionTypes,true)&&in_array('agent',$actionTypes,true),'Now Decision item provides Research navigation and a read-only Ask Agent action.');

$private=research_report_build_snapshot($pdo,$project,'private','Private Decision Report','Private integration snapshot',$owner);
$privateIds=array_column((array)$private['decisions'],'id');
p71s6(in_array($accepted['public_id'],$privateIds,true)&&in_array($draft['public_id'],$privateIds,true)&&in_array($rejected['public_id'],$privateIds,true),'Private report snapshot includes non-archived Decision working state.');
$privateAccepted=null;foreach($private['decisions'] as $d)if($d['id']===$accepted['public_id']){$privateAccepted=$d;break;}
p71s6($privateAccepted!==null&&!empty($privateAccepted['challenges'])&&!empty($privateAccepted['reconsiderations'])&&!empty($privateAccepted['outcomes']),'Private report projection includes challenges, reconsiderations, and Outcome Memory.');
p71s6(($privateAccepted['evidence'][0]['note']??'')!=='','Private report projection preserves internal Decision evidence notes.');

$public=research_report_build_snapshot($pdo,$project,'public','Public Decision Report','Public integration snapshot',$owner);
$publicIds=array_column((array)$public['decisions'],'id');
p71s6(in_array($accepted['public_id'],$publicIds,true)&&!in_array($draft['public_id'],$publicIds,true)&&!in_array($rejected['public_id'],$publicIds,true),'Public report includes Accepted Decision but excludes draft/rejected internal state.');
$publicAccepted=null;foreach($public['decisions'] as $d)if($d['id']===$accepted['public_id']){$publicAccepted=$d;break;}
p71s6($publicAccepted!==null&&!array_key_exists('challenges',$publicAccepted)&&!array_key_exists('reconsiderations',$publicAccepted),'Public report excludes internal challenge and reconsideration detail.');
$publicHasNote=false;foreach((array)$publicAccepted['evidence'] as $edge)if(array_key_exists('note',$edge))$publicHasNote=true;
p71s6(!$publicHasNote,'Public report excludes internal Decision evidence notes.');
p71s6(!empty($publicAccepted['outcomes'])&&($publicAccepted['outcomes'][0]['lessons']??'')==='Support capacity must gate expansion.','Public report can deliberately publish observed outcomes and lessons.');
$timelineTypes=array_column((array)$public['timeline'],'type');
p71s6(in_array('decision',$timelineTypes,true)&&in_array('decision_outcome',$timelineTypes,true),'Report timeline includes Decision and observed outcome events.');

$after=research_decision_detail($pdo,$owner,(string)$accepted['public_id']);
p71s6((string)$after['status']===$beforeStatus&&(int)$after['current_revision']===$beforeRevision,'Agent, Now, and Report Studio integrations are read-only against Decision state.');
p71s6(($case['status']??'')==='open','Read-only integrations do not resolve or apply reconsideration cases.');

echo "Phase 71 Section 6 Agent / Now / Report Studio integration database journey passed.\n";
