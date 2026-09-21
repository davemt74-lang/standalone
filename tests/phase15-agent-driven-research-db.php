<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
require_once $root.'/app/installer.php';require_once $root.'/app/storage.php';require_once $root.'/app/functions.php';require_once $root.'/app/access.php';require_once $root.'/app/jobs.php';require_once $root.'/app/ai.php';require_once $root.'/app/source-integrity.php';require_once $root.'/app/annotation-intelligence.php';require_once $root.'/app/research-workspace.php';require_once $root.'/app/conversations.php';require_once $root.'/app/agent-actions.php';require_once $root.'/app/agent-chat.php';
function p15(bool $v,string $m): void {if(!$v)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function p15throws(callable $fn,string $class,string $m): void {try{$fn();}catch(Throwable $e){if($e instanceof $class){echo "PASS: $m\n";return;}throw $e;}throw new RuntimeException('FAIL: '.$m);}
$run='p15'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('ActionOwner');$researcher=$makeUser('ActionResearcher');$viewer=$makeUser('ActionViewer');$outsider=$makeUser('ActionOutsider');
p15(agent_actions_ready($pdo),'Phase 15 Agent action schema is available');
$caps=agent_action_capabilities();foreach(['research.create_task','research.create_note','research.create_claim','research.attach_annotation_evidence','research.create_finding','research.link_claims'] as $key)p15(isset($caps[$key]),'capability registry includes '.$key);

$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Action Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher'),(?,?,'viewer')")->execute([$teamId,$owner['id'],$teamId,$researcher['id'],$teamId,$viewer['id']]);
$projectPublic=$pub('project');$pdo->prepare('INSERT INTO research_projects(public_id,owner_user_id,team_id,title,description) VALUES(?,?,?,?,?)')->execute([$projectPublic,$owner['id'],$teamId,'Agent Action Project','Phase 15 CI project']);$projectId=(int)$pdo->lastInsertId();

$url='https://example.com/'.$run.'/evidence';$sourcePublic=$pub('source');$pdo->prepare("INSERT INTO sources(public_id,source_type,canonical_url,canonical_url_hash,domain,title,status) VALUES(?,'webpage',?,?,?,'Action Evidence','current')")->execute([$sourcePublic,$url,hash('sha256',$url),'example.com']);$sourceId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,1,?,'Action Evidence','Evidence supporting Phase 15 actions',?)")->execute([$sourceId,$url,hash('sha256','phase15 evidence')]);$versionId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$versionId,$sourceId]);$pdo->prepare('INSERT INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([$projectId,$sourceId,$owner['id']]);

$capPublic=$pub('cap');$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?, 'text',?)")->execute([$capPublic,$sourceId,$versionId,$owner['id'],'Public annotation evidence']);$captureId=(int)$pdo->lastInsertId();
$annotationPublic=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,status) VALUES(?,?,?,?,?,?,'public','published')")->execute([$annotationPublic,$owner['id'],$sourceId,$versionId,$captureId,'Evidence annotation']);$annotationId=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO project_annotations(project_id,annotation_id,added_by_user_id) VALUES(?,?,?)')->execute([$projectId,$annotationId,$owner['id']]);

$privateCap=$pub('cap');$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?, 'text',?)")->execute([$privateCap,$sourceId,$versionId,$outsider['id'],'Private evidence']);$privateCapture=(int)$pdo->lastInsertId();
$privateAnnotation=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,status) VALUES(?,?,?,?,?,?,'private','published')")->execute([$privateAnnotation,$outsider['id'],$sourceId,$versionId,$privateCapture,'Private outsider evidence']);

$claim1=$pub('claim');$claim2=$pub('claim');$pdo->prepare("INSERT INTO research_claims(public_id,project_id,created_by_user_id,statement,claim_type,status) VALUES(?,?,?,'Claim one','factual','unverified'),(?,?,?,'Claim two','factual','unverified')")->execute([$claim1,$projectId,$owner['id'],$claim2,$projectId,$owner['id']]);

$conversation=agent_chat_create($pdo,$owner,'Phase 15 action test');$userMessage=conversation_message_create($pdo,$owner,$conversation['public_id'],'Create a follow-up task',null,'client-'.$run);$assistant=agent_chat_insert_agent_message($pdo,$conversation,'I can prepare that for confirmation.',(int)$userMessage['id']);
$context=[agent_chat_context_item($pdo,$owner,'research',$projectPublic)];$refs=$context[0]['refs'];
$notSuppliedClaim=$pub('claim');$pdo->prepare("INSERT INTO research_claims(public_id,project_id,created_by_user_id,statement,claim_type,status) VALUES(?,?,?,'Real but not supplied to Agent','factual','unverified')")->execute([$notSuppliedClaim,$projectId,$owner['id']]);

$parsed=agent_action_extract("I can prepare a task.\n<<ANNOTATED_ACTIONS>>[{\"capability\":\"research.create_task\",\"project_id\":\"".$projectPublic."\",\"arguments\":{\"title\":\"Verify the claim\",\"task_type\":\"verify_claim\"}}]");
p15($parsed['body']==='I can prepare a task.'&&count($parsed['actions'])===1,'Agent action marker is removed from user-visible prose and parsed safely');
$badParsed=agent_action_extract("<<ANNOTATED_ACTIONS>>not-json");p15(!str_contains((string)$badParsed['body'],'ANNOTATED_ACTIONS')&&count($badParsed['actions'])===0,'malformed machine-action payload cannot leak into user-visible Agent prose');

$proposals=agent_action_create_proposals($pdo,$owner,$conversation,(int)$assistant['id'],$context,[
 ['capability'=>'research.create_task','project_id'=>$projectPublic,'arguments'=>['title'=>'Verify the claim','description'=>'Find independent evidence.','task_type'=>'verify_claim']],
 ['capability'=>'research.link_claims','project_id'=>$projectPublic,'arguments'=>['source_claim_id'=>'invented','target_claim_id'=>$claim2,'relation_type'=>'supports']]
],$refs);
$hiddenRefAttempt=agent_action_create_proposals($pdo,$owner,$conversation,(int)$assistant['id'],$context,[['capability'=>'research.link_claims','project_id'=>$projectPublic,'arguments'=>['source_claim_id'=>$claim1,'target_claim_id'=>$notSuppliedClaim,'relation_type'=>'supports']]],$refs);
p15(count($hiddenRefAttempt)===0,'real project IDs that were not supplied to the Agent cannot become proposal references');
p15(count($proposals)===1&&$proposals[0]['capability_key']==='research.create_task','invalid hallucinated Research references are discarded before proposal display');
$proposal=$proposals[0];$q=$pdo->prepare('SELECT COUNT(*) FROM research_tasks WHERE project_id=?');$q->execute([$projectId]);p15((int)$q->fetchColumn()===0,'proposal does not mutate Research before confirmation');
p15(($proposal['status']??'')==='pending'&&count($proposal['provenance']['refs']??[])>0,'pending proposal stores provenance references');

$same=agent_action_create_proposals($pdo,$owner,$conversation,(int)$assistant['id'],$context,[['capability'=>'research.create_task','project_id'=>$projectPublic,'arguments'=>['title'=>'Verify the claim','description'=>'Find independent evidence.','task_type'=>'verify_claim']]],$refs);
p15(count($same)===1&&$same[0]['public_id']===$proposal['public_id'],'identical proposal retry resolves the existing deduplicated proposal');
$q=$pdo->prepare('SELECT COUNT(*) FROM agent_action_proposals WHERE assistant_message_id=?');$q->execute([$assistant['id']]);p15((int)$q->fetchColumn()===1,'proposal retry does not create duplicate rows');

$executed=agent_action_confirm_execute($pdo,$owner,$proposal['public_id']);p15(($executed['status']??'')==='executed'&&($executed['result']['type']??'')==='task','explicit confirmation executes the bounded task capability');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_tasks WHERE project_id=? AND title=?');$q->execute([$projectId,'Verify the claim']);p15((int)$q->fetchColumn()===1,'confirmed Agent task creates one Research task');
$again=agent_action_confirm_execute($pdo,$owner,$proposal['public_id']);p15(!empty($again['deduplicated']),'repeated confirmation replays the prior result idempotently');$q->execute([$projectId,'Verify the claim']);p15((int)$q->fetchColumn()===1,'confirmation retry cannot duplicate Research writes');
$q=$pdo->prepare('SELECT GROUP_CONCAT(event_type ORDER BY id) FROM agent_action_events WHERE proposal_id=(SELECT id FROM agent_action_proposals WHERE public_id=?)');$q->execute([$proposal['public_id']]);$events=(string)$q->fetchColumn();p15(str_contains($events,'proposed')&&str_contains($events,'confirmed')&&str_contains($events,'executed'),'proposal confirmation and execution produce immutable audit events');

$userMessage2=conversation_message_create($pdo,$owner,$conversation['public_id'],'Save a note',null,'client-note-'.$run);$assistant2=agent_chat_insert_agent_message($pdo,$conversation,'I can save that after confirmation.',(int)$userMessage2['id']);
$noteProposal=agent_action_create_proposals($pdo,$owner,$conversation,(int)$assistant2['id'],$context,[['capability'=>'research.create_note','project_id'=>$projectPublic,'arguments'=>['body'=>'Potential follow-up note']]],$refs)[0];
$rejected=agent_action_reject($pdo,$owner,$noteProposal['public_id']);p15(($rejected['status']??'')==='rejected','user can reject an Agent Research action');
$q=$pdo->prepare("SELECT COUNT(*) FROM research_notes WHERE project_id=? AND body='Potential follow-up note'");$q->execute([$projectId]);p15((int)$q->fetchColumn()===0,'rejected Agent action never writes Research data');

$userMessage3=conversation_message_create($pdo,$owner,$conversation['public_id'],'Create claim',null,'client-stale-'.$run);$assistant3=agent_chat_insert_agent_message($pdo,$conversation,'I can propose a claim.',(int)$userMessage3['id']);
$staleProposal=agent_action_create_proposals($pdo,$owner,$conversation,(int)$assistant3['id'],$context,[['capability'=>'research.create_claim','project_id'=>$projectPublic,'arguments'=>['statement'=>'Agent-created stale claim','claim_type'=>'factual']]],$refs)[0];
$pdo->prepare('INSERT INTO research_notes(public_id,project_id,user_id,body) VALUES(?,?,?,?)')->execute([$pub('note'),$projectId,$owner['id'],'External project change before confirmation']);
p15throws(fn()=>agent_action_confirm_execute($pdo,$owner,$staleProposal['public_id']),AgentActionStale::class,'project-state hash blocks stale Agent actions');
$q=$pdo->prepare("SELECT COUNT(*) FROM research_claims WHERE project_id=? AND statement='Agent-created stale claim'");$q->execute([$projectId]);p15((int)$q->fetchColumn()===0,'stale proposal cannot mutate changed Research state');
$staleRow=agent_action_proposal_row($pdo,$owner,$staleProposal['public_id']);p15(($staleRow['status']??'')==='stale','stale proposal is persisted as non-executable');

$researchConversation=agent_chat_create($pdo,$researcher,'Researcher action test');$rm=conversation_message_create($pdo,$researcher,$researchConversation['public_id'],'Create task',null,'client-r-'.$run);$ra=agent_chat_insert_agent_message($pdo,$researchConversation,'Proposed.',(int)$rm['id']);$researchContext=[agent_chat_context_item($pdo,$researcher,'research',$projectPublic)];
$researchProposal=agent_action_create_proposals($pdo,$researcher,$researchConversation,(int)$ra['id'],$researchContext,[['capability'=>'research.create_task','project_id'=>$projectPublic,'arguments'=>['title'=>'Researcher task','task_type'=>'general']]],$researchContext[0]['refs'])[0];
$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$researcher['id']]);
p15throws(fn()=>agent_action_confirm_execute($pdo,$researcher,$researchProposal['public_id']),AgentActionForbidden::class,'permission loss between proposal and confirmation blocks execution');
$q=$pdo->prepare("SELECT COUNT(*) FROM research_tasks WHERE project_id=? AND title='Researcher task'");$q->execute([$projectId]);p15((int)$q->fetchColumn()===0,'revoked Research collaborator cannot execute a pending Agent write');

$viewerConversation=agent_chat_create($pdo,$viewer,'Viewer action test');$vm=conversation_message_create($pdo,$viewer,$viewerConversation['public_id'],'Create task',null,'client-v-'.$run);$va=agent_chat_insert_agent_message($pdo,$viewerConversation,'Attempted proposal.',(int)$vm['id']);$viewerContext=[agent_chat_context_item($pdo,$viewer,'research',$projectPublic)];
$viewerProposals=agent_action_create_proposals($pdo,$viewer,$viewerConversation,(int)$va['id'],$viewerContext,[['capability'=>'research.create_task','project_id'=>$projectPublic,'arguments'=>['title'=>'Viewer task','task_type'=>'general']]],$viewerContext[0]['refs']);
p15(count($viewerProposals)===0,'read-only project viewers are never given executable proposal cards');

$ownerContext=[agent_chat_context_item($pdo,$owner,'research',$projectPublic)];
$mk=function(string $prompt,array $action)use($pdo,$owner,$conversation,$ownerContext,$pub,$run){$m=conversation_message_create($pdo,$owner,$conversation['public_id'],$prompt,null,'client-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6));$a=agent_chat_insert_agent_message($pdo,$conversation,'Prepared action.',(int)$m['id']);return agent_action_create_proposals($pdo,$owner,$conversation,(int)$a['id'],$ownerContext,[$action],$ownerContext[0]['refs'])[0]??null;};

$findingP=$mk('Create finding',['capability'=>'research.create_finding','project_id'=>$projectPublic,'arguments'=>['title'=>'Synthesized finding','summary'=>'Finding based on two project claims.','claim_ids'=>[$claim1,$claim2]]]);p15($findingP!==null,'Agent can propose a Finding from existing project Claims');$findingResult=agent_action_confirm_execute($pdo,$owner,$findingP['public_id']);$findingId=$findingResult['result']['public_id'];$q=$pdo->prepare('SELECT COUNT(*) FROM finding_claims fc JOIN research_findings rf ON rf.id=fc.finding_id WHERE rf.public_id=?');$q->execute([$findingId]);p15((int)$q->fetchColumn()===2,'confirmed Finding preserves proposed Claim provenance links');

$evidenceP=$mk('Attach evidence',['capability'=>'research.attach_annotation_evidence','project_id'=>$projectPublic,'arguments'=>['claim_id'=>$claim1,'annotation_id'=>$annotationPublic,'relationship'=>'supports','note'=>'Agent-proposed evidence link']]);p15($evidenceP!==null,'Agent can propose accessible Annotation evidence');agent_action_confirm_execute($pdo,$owner,$evidenceP['public_id']);$q=$pdo->prepare("SELECT COUNT(*) FROM claim_evidence ce JOIN research_claims rc ON rc.id=ce.claim_id WHERE rc.public_id=? AND ce.annotation_id=? AND ce.relationship='supports'");$q->execute([$claim1,$annotationId]);p15((int)$q->fetchColumn()===1,'confirmed evidence proposal attaches the captured Annotation version to the Claim');

$invalidEvidence=$mk('Attach private evidence',['capability'=>'research.attach_annotation_evidence','project_id'=>$projectPublic,'arguments'=>['claim_id'=>$claim1,'annotation_id'=>$privateAnnotation,'relationship'=>'supports']]);p15($invalidEvidence===null,'inaccessible private Annotation cannot become an Agent proposal');

$linkP=$mk('Link claims',['capability'=>'research.link_claims','project_id'=>$projectPublic,'arguments'=>['source_claim_id'=>$claim1,'target_claim_id'=>$claim2,'relation_type'=>'contradicts','note'=>'Conflicting project claims']]);p15($linkP!==null,'Agent can propose a bounded Claim relationship');agent_action_confirm_execute($pdo,$owner,$linkP['public_id']);$q=$pdo->prepare("SELECT COUNT(*) FROM claim_relations WHERE project_id=? AND relation_type='contradicts'");$q->execute([$projectId]);p15((int)$q->fetchColumn()===1,'confirmed Claim relationship writes one typed graph edge');

echo "Phase 15 Agent-Driven Research MariaDB suite passed.\n";
