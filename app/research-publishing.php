<?php
declare(strict_types=1);

function research_publications_ready(PDO $pdo): bool {
    try{
        foreach(['research_publication_workflows','research_publication_review_rounds','research_review_threads','research_review_thread_messages','research_publication_approval_gates','research_publication_distribution_targets','research_publication_distribution_events','research_publication_events'] as $table)if(!installer_table_exists($pdo,$table))return false;
        return true;
    }catch(Throwable $e){return false;}
}

function research_publication_statuses(): array {
    return ['draft'=>'Draft','in_review'=>'In Review','changes_requested'=>'Changes Requested','approved'=>'Approved','published'=>'Published','archived'=>'Archived'];
}

function research_publication_default_policy(): array {
    return ['required_approvals'=>1,'owner_approval'=>true,'review_complete'=>true,'no_unresolved_threads'=>true,'no_failed_task_gates'=>true,'no_high_contradictions'=>true,'fresh_evidence_days'=>30];
}

function research_publication_clean_policy(array $input): array {
    $p=array_merge(research_publication_default_policy(),is_array($input['policy']??null)?$input['policy']:[]);
    foreach(array_keys($p) as $key)if(array_key_exists($key,$input))$p[$key]=$input[$key];
    return [
      'required_approvals'=>max(1,min(20,(int)($p['required_approvals']??1))),
      'owner_approval'=>(bool)($p['owner_approval']??true),
      'review_complete'=>(bool)($p['review_complete']??true),
      'no_unresolved_threads'=>(bool)($p['no_unresolved_threads']??true),
      'no_failed_task_gates'=>(bool)($p['no_failed_task_gates']??true),
      'no_high_contradictions'=>(bool)($p['no_high_contradictions']??true),
      'fresh_evidence_days'=>max(0,min(3650,(int)($p['fresh_evidence_days']??30))),
    ];
}

function research_publication_event(PDO $pdo,int $workflowId,string $event,string $actor='system',?int $actorUserId=null,array $payload=[]): void {
    $actor=in_array($actor,['user','agent','system'],true)?$actor:'system';
    $pdo->prepare("INSERT INTO research_publication_events(public_id,workflow_id,event_type,actor_type,actor_user_id,payload_json) VALUES(?,?,?,?,?,?)")
      ->execute([ulid_like(),$workflowId,mb_substr($event,0,64),$actor,$actorUserId,$payload?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
}

function research_publication_document_revision(PDO $pdo,array $viewer,string $documentPublic): array {
    $doc=research_agent_workspace_object($pdo,$viewer,trim($documentPublic),false);if(!$doc||($doc['object_type']??'')!=='document')throw new RuntimeException('Research document is unavailable.');
    $q=$pdo->prepare("SELECT public_id,revision_number,title,content_html,plain_text,summary,edited_by_user_id,created_at FROM research_workspace_document_revisions WHERE document_object_id=? AND revision_number=? LIMIT 1");
    $q->execute([(int)$doc['id'],(int)$doc['revision_number']]);$rev=$q->fetch();if(!$rev)throw new RuntimeException('Current Research document revision is unavailable.');
    $hash=hash('sha256',json_encode(['document_public_id'=>$doc['public_id'],'revision_number'=>(int)$rev['revision_number'],'title'=>$rev['title'],'content_html'=>$rev['content_html'],'plain_text'=>$rev['plain_text'],'summary'=>$rev['summary']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    return ['document'=>$doc,'revision'=>$rev,'hash'=>$hash];
}

function research_publication_workflow_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_publications_ready($pdo))return null;
    $q=$pdo->prepare("SELECT rpw.*,rp.public_id project_public_id,rp.title project_title,rp.owner_user_id,rp.team_id,rwo.public_id document_public_id,rwo.title document_title,ra.public_id agent_public_id,ra.name agent_name,ac.public_id conversation_public_id,rr.public_id review_public_id,
      rtr.public_id plan_public_id,rpr.public_id program_run_public_id,rep.public_id published_report_public_id,rv.public_id published_version_public_id,rv.version_number published_version_number
      FROM research_publication_workflows rpw
      JOIN research_projects rp ON rp.id=rpw.project_id
      JOIN research_workspace_objects rwo ON rwo.id=rpw.document_object_id
      LEFT JOIN research_agents ra ON ra.id=rpw.research_agent_id
      LEFT JOIN conversations ac ON ac.id=ra.conversation_id
      LEFT JOIN research_reviews rr ON rr.id=rpw.current_review_id
      LEFT JOIN research_reports rep ON rep.id=rpw.published_report_id
      LEFT JOIN research_report_versions rv ON rv.id=rpw.published_version_id
      LEFT JOIN research_task_plans rtr ON rtr.id=rpw.source_plan_id
      LEFT JOIN research_program_runs rpr ON rpr.id=rpw.source_program_run_id
      WHERE rpw.public_id=? LIMIT 1");
    $q->execute([trim($publicId)]);$w=$q->fetch();if(!$w)return null;
    if(!project_access($pdo,(int)$viewer['id'],(string)$w['project_public_id']))return null;
    $w['policy']=json_decode((string)$w['policy_json'],true)?:research_publication_default_policy();
    $w['topics']=json_decode((string)($w['topics_json']??''),true)?:[];
    $w['approval_snapshot']=$w['approval_snapshot_json']?json_decode((string)$w['approval_snapshot_json'],true):null;
    return $w;
}

function research_publication_can_manage(array $viewer,array $workflow): bool {
    return ($viewer['role']??'')==='admin'||(int)$workflow['owner_user_id']===(int)$viewer['id']||(int)$workflow['created_by_user_id']===(int)$viewer['id'];
}

function research_publication_source_links(PDO $pdo,int $documentObjectId): array {
    $q=$pdo->prepare("SELECT rtd.plan_id,rtp.research_agent_id,rtp.program_run_id FROM research_task_deliverables rtd JOIN research_task_plans rtp ON rtp.id=rtd.plan_id WHERE rtd.workspace_object_id=? ORDER BY rtd.id DESC LIMIT 1");$q->execute([$documentObjectId]);$r=$q->fetch();
    return $r?['plan_id'=>(int)$r['plan_id'],'research_agent_id'=>(int)$r['research_agent_id'],'program_run_id'=>$r['program_run_id']!==null?(int)$r['program_run_id']:null]:['plan_id'=>null,'research_agent_id'=>null,'program_run_id'=>null];
}

function research_publication_revision_pin(PDO $pdo,array $viewer,string $documentPublic): array {
    $state=research_publication_document_revision($pdo,$viewer,$documentPublic);return [
      'number'=>(int)$state['revision']['revision_number'],'public_id'=>(string)$state['revision']['public_id'],'hash'=>(string)$state['hash'],'state'=>$state
    ];
}

function research_publication_add_gates(PDO $pdo,int $workflowId,array $policy): void {
    $gates=[
      'required_approvals'=>['count'=>(int)$policy['required_approvals']],
      'owner_approval'=>['value'=>(bool)$policy['owner_approval']],
      'review_complete'=>['value'=>(bool)$policy['review_complete']],
      'no_unresolved_threads'=>['value'=>(bool)$policy['no_unresolved_threads']],
      'no_failed_task_gates'=>['value'=>(bool)$policy['no_failed_task_gates']],
      'no_high_contradictions'=>['value'=>(bool)$policy['no_high_contradictions']],
      'fresh_evidence'=>['days'=>(int)$policy['fresh_evidence_days']],
    ];
    $q=$pdo->prepare("INSERT INTO research_publication_approval_gates(workflow_id,gate_type,required_json) VALUES(?,?,?) ON DUPLICATE KEY UPDATE required_json=VALUES(required_json),status='pending',detail=NULL,evaluated_at=NULL,updated_at=NOW()");
    foreach($gates as $type=>$required)$q->execute([$workflowId,$type,json_encode($required,JSON_UNESCAPED_SLASHES)]);
}

function research_publication_set_targets(PDO $pdo,array $viewer,array $workflow,array $input): void {
    if(!research_publication_can_manage($viewer,$workflow))throw new RuntimeException('You cannot change this publication distribution.');
    $pdo->prepare("DELETE FROM research_publication_distribution_targets WHERE workflow_id=?")->execute([(int)$workflow['id']]);
    $ins=$pdo->prepare("INSERT INTO research_publication_distribution_targets(workflow_id,target_type,target_user_id,target_team_id,notify_in_app,created_by_user_id) VALUES(?,?,?,?,?,?)");
    $seen=[];$eligible=[];foreach(research_review_eligible_reviewers($pdo,$viewer,(string)$workflow['project_public_id']) as $person)$eligible[(int)$person['id']]=true;
    foreach(array_slice((array)($input['recipient_user_ids']??[]),0,100) as $id){$id=(int)$id;if($id<1||isset($seen['u'.$id])||!isset($eligible[$id]))continue;$seen['u'.$id]=true;$ins->execute([(int)$workflow['id'],'user',$id,null,1,(int)$viewer['id']]);}
    if(!empty($input['notify_team'])&&!empty($workflow['team_id']))$ins->execute([(int)$workflow['id'],'team',null,(int)$workflow['team_id'],1,(int)$viewer['id']]);
    if(!array_key_exists('notify_subscribers',$input)||(bool)$input['notify_subscribers'])$ins->execute([(int)$workflow['id'],'subscribers',null,null,1,(int)$viewer['id']]);
}

function research_publication_workflow_create(PDO $pdo,array $viewer,string $documentPublic,array $input=[]): array {
    if(!research_publications_ready($pdo))throw new RuntimeException('Collaborative Publishing requires the latest database upgrade.');
    $pin=research_publication_revision_pin($pdo,$viewer,$documentPublic);$doc=$pin['state']['document'];$project=project_access($pdo,(int)$viewer['id'],(string)$doc['project_public_id']);if(!$project||!project_can_write($project))throw new RuntimeException('Write access to the Research project is required.');
    $q=$pdo->prepare("SELECT public_id FROM research_publication_workflows WHERE document_object_id=? AND status NOT IN ('published','archived') ORDER BY id DESC LIMIT 1");$q->execute([(int)$doc['id']]);$existing=(string)($q->fetchColumn()?:'');if($existing!==''){ $w=research_publication_workflow_access($pdo,$viewer,$existing);if($w)return $w;}
    $policy=research_publication_clean_policy($input);$visibility=(string)($input['visibility']??($project['team_id']?'team':'private'));if(!in_array($visibility,['public','team','private'],true))$visibility='private';if($visibility==='team'&&!$project['team_id'])$visibility='private';
    $title=mb_substr(trim((string)($input['title']??$doc['title'])),0,255);if($title==='')$title=(string)$doc['title'];$summary=mb_substr(trim((string)($input['summary']??$doc['document_summary']??'')),0,5000);
    $topics=function_exists('living_research_parse_topics')?living_research_parse_topics($input['topics']??[]):[];$links=research_publication_source_links($pdo,(int)$doc['id']);$public=ulid_like();
    $pdo->prepare("INSERT INTO research_publication_workflows(public_id,project_id,document_object_id,created_by_user_id,research_agent_id,source_plan_id,source_program_run_id,title,summary,visibility,topics_json,status,current_round,document_revision_number,document_revision_public_id,document_hash,policy_json)
      VALUES(?,?,?,?,?,?,?,?,?,?,?,'draft',0,?,?,?,?)")
      ->execute([$public,(int)$project['id'],(int)$doc['id'],(int)$viewer['id'],$links['research_agent_id'],$links['plan_id'],$links['program_run_id'],$title,$summary!==''?$summary:null,$visibility,json_encode(array_values($topics),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$pin['number'],$pin['public_id'],$pin['hash'],json_encode($policy,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    $id=(int)$pdo->lastInsertId();research_publication_add_gates($pdo,$id,$policy);$workflow=research_publication_workflow_access($pdo,$viewer,$public);research_publication_set_targets($pdo,$viewer,$workflow,$input);research_publication_event($pdo,$id,'created','user',(int)$viewer['id'],['document_revision'=>$pin['number'],'policy'=>$policy]);return research_publication_workflow_access($pdo,$viewer,$public)??$workflow;
}

function research_publication_workflow_update(PDO $pdo,array $viewer,string $publicId,array $input): array {
    $w=research_publication_workflow_access($pdo,$viewer,$publicId);if(!$w)throw new RuntimeException('Publication workflow not found.');if(!research_publication_can_manage($viewer,$w))throw new RuntimeException('You cannot edit this publication workflow.');if(in_array($w['status'],['published','archived'],true))throw new RuntimeException('Published or archived workflows are immutable.');
    $policy=research_publication_clean_policy(array_merge(['policy'=>$w['policy']],$input));$title=mb_substr(trim((string)($input['title']??$w['title'])),0,255);$summary=mb_substr(trim((string)($input['summary']??$w['summary']??'')),0,5000);$visibility=(string)($input['visibility']??$w['visibility']);if(!in_array($visibility,['public','team','private'],true))$visibility=(string)$w['visibility'];if($visibility==='team'&&!$w['team_id'])$visibility='private';$topics=array_key_exists('topics',$input)?array_values(living_research_parse_topics($input['topics'])):$w['topics'];
    $pdo->prepare("UPDATE research_publication_workflows SET title=?,summary=?,visibility=?,topics_json=?,policy_json=?,owner_approved_by_user_id=NULL,owner_approved_at=NULL,approval_snapshot_json=NULL,status=CASE WHEN status='approved' THEN 'in_review' ELSE status END,updated_at=NOW() WHERE id=?")
      ->execute([$title,$summary!==''?$summary:null,$visibility,json_encode($topics,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),json_encode($policy,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),(int)$w['id']]);research_publication_add_gates($pdo,(int)$w['id'],$policy);$fresh=research_publication_workflow_access($pdo,$viewer,$publicId);if(isset($input['recipient_user_ids'])||isset($input['notify_team'])||isset($input['notify_subscribers']))research_publication_set_targets($pdo,$viewer,$fresh,$input);research_publication_event($pdo,(int)$w['id'],'updated','user',(int)$viewer['id'],['policy'=>$policy]);return research_publication_workflow_access($pdo,$viewer,$publicId)??$fresh;
}

function research_publication_assignment_input(array $reviewers): array {
    $ids=[];$options=[];foreach(array_slice($reviewers,0,50) as $item){if(is_numeric($item))$item=['user_id'=>(int)$item];if(!is_array($item))continue;$id=(int)($item['user_id']??0);if($id<1)continue;$role=in_array((string)($item['role']??'reviewer'),['reviewer','approver'],true)?(string)($item['role']??'reviewer'):'reviewer';$required=array_key_exists('required',$item)?(bool)$item['required']:true;$ids[$id]=$id;$options[$id]=['role'=>$role,'required'=>$required];}return ['ids'=>array_values($ids),'options'=>$options];
}

function research_publication_request_review(PDO $pdo,array $viewer,string $publicId,array $reviewers,?string $dueAt=null,string $instructions=''): array {
    $w=research_publication_workflow_access($pdo,$viewer,$publicId);if(!$w)throw new RuntimeException('Publication workflow not found.');if(!research_publication_can_manage($viewer,$w))throw new RuntimeException('You cannot request review for this workflow.');if(in_array($w['status'],['published','archived'],true))throw new RuntimeException('This publication workflow is read-only.');
    $pin=research_publication_revision_pin($pdo,$viewer,(string)$w['document_public_id']);$assign=research_publication_assignment_input($reviewers);if(!$assign['ids'])throw new InvalidArgumentException('Choose at least one reviewer.');
    $review=research_review_create($pdo,$viewer,'document',(string)$w['document_public_id'],$assign['ids'],$dueAt,$instructions,$assign['options']);$round=(int)$w['current_round']+1;
    $pdo->beginTransaction();try{$pdo->prepare("UPDATE research_publication_workflows SET current_review_id=?,current_round=?,document_revision_number=?,document_revision_public_id=?,document_hash=?,status='in_review',owner_approved_by_user_id=NULL,owner_approved_at=NULL,approval_snapshot_json=NULL,updated_at=NOW() WHERE id=?")
      ->execute([(int)$review['id'],$round,$pin['number'],$pin['public_id'],$pin['hash'],(int)$w['id']]);$pdo->prepare("INSERT INTO research_publication_review_rounds(public_id,workflow_id,round_number,review_id,document_revision_number,document_revision_public_id,document_hash,started_by_user_id) VALUES(?,?,?,?,?,?,?,?)")
      ->execute([ulid_like(),(int)$w['id'],$round,(int)$review['id'],$pin['number'],$pin['public_id'],$pin['hash'],(int)$viewer['id']]);research_publication_event($pdo,(int)$w['id'],'review_requested','user',(int)$viewer['id'],['review_id'=>$review['public_id'],'round'=>$round,'document_revision'=>$pin['number']]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    research_publication_evaluate($pdo,$viewer,$publicId);return research_publication_workflow_access($pdo,$viewer,$publicId)??$w;
}

function research_publication_restart_review(PDO $pdo,array $viewer,string $publicId,?string $dueAt=null): array {
    $w=research_publication_workflow_access($pdo,$viewer,$publicId);if(!$w||empty($w['review_public_id']))throw new RuntimeException('Publication review is unavailable.');if(!research_publication_can_manage($viewer,$w))throw new RuntimeException('You cannot restart this review.');
    $old=research_review_access($pdo,$viewer,(string)$w['review_public_id']);if(!$old)throw new RuntimeException('Publication review is unavailable.');$new=research_review_restart($pdo,$viewer,(string)$old['public_id'],$dueAt);$pin=research_publication_revision_pin($pdo,$viewer,(string)$w['document_public_id']);$round=(int)$w['current_round']+1;
    $pdo->prepare("UPDATE research_publication_workflows SET current_review_id=?,current_round=?,document_revision_number=?,document_revision_public_id=?,document_hash=?,status='in_review',owner_approved_by_user_id=NULL,owner_approved_at=NULL,approval_snapshot_json=NULL,updated_at=NOW() WHERE id=?")->execute([(int)$new['id'],$round,$pin['number'],$pin['public_id'],$pin['hash'],(int)$w['id']]);
    $pdo->prepare("INSERT INTO research_publication_review_rounds(public_id,workflow_id,round_number,review_id,document_revision_number,document_revision_public_id,document_hash,started_by_user_id) VALUES(?,?,?,?,?,?,?,?)")->execute([ulid_like(),(int)$w['id'],$round,(int)$new['id'],$pin['number'],$pin['public_id'],$pin['hash'],(int)$viewer['id']]);research_publication_event($pdo,(int)$w['id'],'review_restarted','user',(int)$viewer['id'],['previous_review_id'=>$old['public_id'],'review_id'=>$new['public_id'],'round'=>$round]);research_publication_evaluate($pdo,$viewer,$publicId);return research_publication_workflow_access($pdo,$viewer,$publicId)??$w;
}

function research_publication_thread_access(PDO $pdo,array $viewer,string $threadPublic): ?array {
    $q=$pdo->prepare("SELECT rrt.*,rr.public_id review_public_id,rpw.public_id workflow_public_id,rpw.project_id,rp.public_id project_public_id,rp.owner_user_id FROM research_review_threads rrt JOIN research_reviews rr ON rr.id=rrt.review_id LEFT JOIN research_publication_workflows rpw ON rpw.id=rrt.workflow_id JOIN research_projects rp ON rp.id=rr.project_id WHERE rrt.public_id=? LIMIT 1");$q->execute([$threadPublic]);$t=$q->fetch();if(!$t||!project_access($pdo,(int)$viewer['id'],(string)$t['project_public_id']))return null;return $t;
}

function research_publication_validate_anchor(PDO $pdo,array $viewer,array $w,string $type,string $public): bool {
    if($type==='general')return true;if($type==='document_section')return $public===''||hash_equals((string)$w['document_public_id'],$public);
    if($type==='claim'){$s=research_review_subject($pdo,$viewer,'claim',$public);return $s&&(int)$s['project_id']===(int)$w['project_id'];}
    if($type==='source'){$q=$pdo->prepare("SELECT 1 FROM project_sources ps JOIN sources s ON s.id=ps.source_id WHERE ps.project_id=? AND s.public_id=? LIMIT 1");$q->execute([(int)$w['project_id'],$public]);return (bool)$q->fetchColumn();}
    if($type==='task'){$q=$pdo->prepare("SELECT 1 FROM research_tasks WHERE project_id=? AND public_id=? LIMIT 1");$q->execute([(int)$w['project_id'],$public]);return (bool)$q->fetchColumn();}
    if($type==='program_delta'){$q=$pdo->prepare("SELECT 1 FROM research_program_deltas WHERE project_id=? AND public_id=? LIMIT 1");$q->execute([(int)$w['project_id'],$public]);return (bool)$q->fetchColumn();}
    if($type==='citation'&&function_exists('research_network_project_references')){foreach(research_network_project_references($pdo,$viewer,(string)$w['project_public_id']) as $ref)if((string)($ref['public_id']??$ref['id']??'')===$public)return true;return false;}
    return false;
}

function research_publication_thread_create(PDO $pdo,array $viewer,string $workflowPublic,array $input): array {
    $w=research_publication_workflow_access($pdo,$viewer,$workflowPublic);if(!$w||empty($w['current_review_id']))throw new RuntimeException('An active publication review is required.');$review=research_review_access($pdo,$viewer,(string)$w['review_public_id']);if(!$review||$review['status']!=='open')throw new RuntimeException('Publication review is not open.');
    $type=(string)($input['anchor_type']??'general');if(!in_array($type,['general','document_section','claim','source','citation','task','program_delta'],true))$type='general';$anchor=trim((string)($input['anchor_public_id']??''));if(!research_publication_validate_anchor($pdo,$viewer,$w,$type,$anchor))throw new InvalidArgumentException('Review thread anchor is not part of this Research project.');
    $body=mb_substr(trim((string)($input['body']??'')),0,12000);if($body==='')throw new InvalidArgumentException('Thread message is required.');$title=mb_substr(trim((string)($input['title']??'')),0,255);$locator=is_array($input['locator']??null)?$input['locator']:[];$public=ulid_like();
    $pdo->beginTransaction();try{$pdo->prepare("INSERT INTO research_review_threads(public_id,review_id,workflow_id,anchor_type,anchor_public_id,locator_json,title,status,created_by_user_id) VALUES(?,?,?,?,?,?,?,'open',?)")->execute([$public,(int)$review['id'],(int)$w['id'],$type,$anchor!==''?$anchor:null,$locator?json_encode($locator,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,$title!==''?$title:null,(int)$viewer['id']]);$threadId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO research_review_thread_messages(public_id,thread_id,user_id,body) VALUES(?,?,?,?)")->execute([ulid_like(),$threadId,(int)$viewer['id'],$body]);research_review_event($pdo,(int)$review['id'],'thread_created',(int)$viewer['id'],['thread_public_id'=>$public,'anchor_type'=>$type,'anchor_public_id'=>$anchor]);research_publication_event($pdo,(int)$w['id'],'thread_created','user',(int)$viewer['id'],['thread_public_id'=>$public]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    research_publication_evaluate($pdo,$viewer,$workflowPublic);return research_publication_thread_access($pdo,$viewer,$public)??['public_id'=>$public];
}

function research_publication_thread_message(PDO $pdo,array $viewer,string $threadPublic,string $body): array {
    $t=research_publication_thread_access($pdo,$viewer,$threadPublic);if(!$t)throw new RuntimeException('Review thread not found.');if($t['status']!=='open')throw new RuntimeException('Resolved review threads are read-only.');$body=mb_substr(trim($body),0,12000);if($body==='')throw new InvalidArgumentException('Thread message is required.');$public=ulid_like();$pdo->prepare("INSERT INTO research_review_thread_messages(public_id,thread_id,user_id,body) VALUES(?,?,?,?)")->execute([$public,(int)$t['id'],(int)$viewer['id'],$body]);$pdo->prepare("UPDATE research_review_threads SET updated_at=NOW() WHERE id=?")->execute([(int)$t['id']]);research_review_event($pdo,(int)$t['review_id'],'thread_commented',(int)$viewer['id'],['thread_public_id'=>$threadPublic,'message_public_id'=>$public]);return ['public_id'=>$public,'body'=>$body];
}

function research_publication_thread_resolve(PDO $pdo,array $viewer,string $threadPublic,bool $resolved=true): array {
    $t=research_publication_thread_access($pdo,$viewer,$threadPublic);if(!$t)throw new RuntimeException('Review thread not found.');$review=research_review_access($pdo,$viewer,(string)$t['review_public_id']);if(!$review)throw new RuntimeException('Review is unavailable.');$can=research_review_can_manage($viewer,$review)||(int)$t['created_by_user_id']===(int)$viewer['id'];if(!$can)throw new RuntimeException('You cannot resolve this review thread.');
    if($resolved)$pdo->prepare("UPDATE research_review_threads SET status='resolved',resolved_by_user_id=?,resolved_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$viewer['id'],(int)$t['id']]);else $pdo->prepare("UPDATE research_review_threads SET status='open',resolved_by_user_id=NULL,resolved_at=NULL,updated_at=NOW() WHERE id=?")->execute([(int)$t['id']]);research_review_event($pdo,(int)$t['review_id'],$resolved?'thread_resolved':'thread_reopened',(int)$viewer['id'],['thread_public_id'=>$threadPublic]);if(!empty($t['workflow_public_id']))research_publication_evaluate($pdo,$viewer,(string)$t['workflow_public_id']);return research_publication_thread_access($pdo,$viewer,$threadPublic)??$t;
}

function research_publication_threads(PDO $pdo,array $viewer,string $workflowPublic,int $limit=200): array {
    $w=research_publication_workflow_access($pdo,$viewer,$workflowPublic);if(!$w)return [];$limit=max(1,min(500,$limit));$q=$pdo->prepare("SELECT public_id FROM research_review_threads WHERE workflow_id=? ORDER BY status='open' DESC,id ASC LIMIT ".$limit);$q->execute([(int)$w['id']]);$out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $public){$t=research_publication_thread_access($pdo,$viewer,(string)$public);if(!$t)continue;$mq=$pdo->prepare("SELECT rrtm.public_id,rrtm.body,rrtm.created_at,u.public_id user_public_id,u.display_name,u.username FROM research_review_thread_messages rrtm JOIN users u ON u.id=rrtm.user_id WHERE rrtm.thread_id=? ORDER BY rrtm.id");$mq->execute([(int)$t['id']]);$t['messages']=$mq->fetchAll()?:[];$t['locator']=json_decode((string)($t['locator_json']??''),true)?:[];$out[]=$t;}return $out;
}

function research_publication_required_approvals(PDO $pdo,array $w): array {
    if(empty($w['current_review_id']))return ['approved'=>0,'required'=>max(1,(int)$w['policy']['required_approvals']),'blocking'=>0,'assignments'=>[],'approver_mode'=>false];
    $q=$pdo->prepare("SELECT rra.reviewer_user_id,rra.reviewer_role,rra.is_required,rrr.decision,u.display_name,u.username FROM research_review_assignments rra JOIN users u ON u.id=rra.reviewer_user_id LEFT JOIN research_review_responses rrr ON rrr.id=rra.latest_response_id WHERE rra.review_id=? ORDER BY u.display_name,u.username");$q->execute([(int)$w['current_review_id']]);$rows=$q->fetchAll()?:[];
    $approverMode=(bool)array_filter($rows,fn($r)=>(bool)$r['is_required']&&($r['reviewer_role']??'reviewer')==='approver');$approved=0;$blocking=0;
    foreach($rows as $r){if(!(bool)$r['is_required'])continue;if(in_array($r['decision'],['request_changes','disagree'],true))$blocking++;if($r['decision']==='approve'&&(!$approverMode||($r['reviewer_role']??'reviewer')==='approver'))$approved++;}
    return ['approved'=>$approved,'required'=>max(1,(int)$w['policy']['required_approvals']),'blocking'=>$blocking,'assignments'=>$rows,'approver_mode'=>$approverMode];
}

function research_publication_evaluate(PDO $pdo,array $viewer,string $publicId): array {
    $w=research_publication_workflow_access($pdo,$viewer,$publicId);if(!$w)throw new RuntimeException('Publication workflow not found.');$policy=$w['policy'];$review=$w['review_public_id']?research_review_access($pdo,$viewer,(string)$w['review_public_id']):null;$approval=research_publication_required_approvals($pdo,$w);$results=[];$all=true;
    $q=$pdo->prepare("SELECT * FROM research_publication_approval_gates WHERE workflow_id=? ORDER BY id");$q->execute([(int)$w['id']]);$gates=$q->fetchAll()?:[];
    foreach($gates as $g){$pass=false;$detail='';switch($g['gate_type']){
      case 'required_approvals':$pass=$approval['approved']>=$approval['required']&&$approval['blocking']===0;$detail=$approval['approved'].'/'.$approval['required'].' required approval(s)'.($approval['blocking']?' · '.$approval['blocking'].' blocking response(s)':'');break;
      case 'owner_approval':$pass=empty($policy['owner_approval'])||!empty($w['owner_approved_at']);$detail=$pass?'Owner approval satisfied.':'Project owner approval required.';break;
      case 'review_complete':$pass=empty($policy['review_complete'])||($review&&$review['status']==='completed'&&!$review['is_stale']);$detail=$pass?'Review completion satisfied.':($review&&$review['is_stale']?'Review is stale.':'Review must be completed.');break;
      case 'no_unresolved_threads':$cq=$pdo->prepare("SELECT COUNT(*) FROM research_review_threads WHERE workflow_id=? AND status='open'");$cq->execute([(int)$w['id']]);$count=(int)$cq->fetchColumn();$pass=empty($policy['no_unresolved_threads'])||$count===0;$detail=$count.' unresolved review thread(s).';break;
      case 'no_failed_task_gates':if(empty($policy['no_failed_task_gates'])||empty($w['source_plan_id'])){$pass=true;$detail='No linked task-gate requirement.';}else{$cq=$pdo->prepare("SELECT COUNT(*) FROM research_tasks rt JOIN research_task_completion_gates g ON g.task_id=rt.id WHERE rt.plan_id=? AND g.status='failed'");$cq->execute([(int)$w['source_plan_id']]);$count=(int)$cq->fetchColumn();$pass=$count===0;$detail=$count.' failed task completion gate(s).';}break;
      case 'no_high_contradictions':if(empty($policy['no_high_contradictions'])||!installer_table_exists($pdo,'research_autonomy_observations')){$pass=true;$detail='Contradiction gate not required.';}else{$cq=$pdo->prepare("SELECT COUNT(*) FROM research_autonomy_observations WHERE project_id=? AND observation_type='contradiction' AND status='open' AND severity='high'");$cq->execute([(int)$w['project_id']]);$count=(int)$cq->fetchColumn();$pass=$count===0;$detail=$count.' open high-severity contradiction(s).';}break;
      case 'fresh_evidence':$days=max(0,(int)$policy['fresh_evidence_days']);if($days===0){$pass=true;$detail='Evidence freshness gate disabled.';}else{$cq=$pdo->prepare("SELECT MAX(sv.captured_at) FROM project_sources ps JOIN sources s ON s.id=ps.source_id JOIN source_versions sv ON sv.id=s.current_version_id WHERE ps.project_id=?");$cq->execute([(int)$w['project_id']]);$latest=(string)($cq->fetchColumn()?:'');$pass=$latest!==''&&(strtotime($latest)?:0)>=time()-($days*86400);$detail=$pass?'Fresh evidence available.':'Evidence newer than '.$days.' days is required.';}break;
    }$status=$pass?'passed':'failed';$pdo->prepare("UPDATE research_publication_approval_gates SET status=?,detail=?,evaluated_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$status,$detail,(int)$g['id']]);$results[]=['type'=>$g['gate_type'],'status'=>$status,'detail'=>$detail];if(!$pass)$all=false;}
    $pin=research_publication_revision_pin($pdo,$viewer,(string)$w['document_public_id']);$docChanged=(int)$pin['number']!==(int)$w['document_revision_number']||!hash_equals((string)$pin['hash'],(string)$w['document_hash']);if($docChanged){$all=false;$pdo->prepare("UPDATE research_publication_workflows SET status='changes_requested',owner_approved_by_user_id=NULL,owner_approved_at=NULL,approval_snapshot_json=NULL,updated_at=NOW() WHERE id=? AND status NOT IN ('published','archived')")->execute([(int)$w['id']]);}
    elseif(!in_array($w['status'],['published','archived'],true)){$next=$all?'approved':(($review&&($review['status']==='cancelled'||$review['is_stale']||in_array(research_review_aggregate($pdo,$review)['consensus'],['changes_requested','unresolved_objection'],true)))?'changes_requested':($review?'in_review':'draft'));$pdo->prepare("UPDATE research_publication_workflows SET status=?,updated_at=NOW() WHERE id=?")->execute([$next,(int)$w['id']]);}
    return ['pass'=>$all&&!$docChanged,'document_changed'=>$docChanged,'gates'=>$results,'approval'=>$approval];
}

function research_publication_owner_approve(PDO $pdo,array $viewer,string $publicId): array {
    $w=research_publication_workflow_access($pdo,$viewer,$publicId);if(!$w)throw new RuntimeException('Publication workflow not found.');if(($viewer['role']??'')!=='admin'&&(int)$w['owner_user_id']!==(int)$viewer['id'])throw new RuntimeException('Project owner approval is required.');if(in_array($w['status'],['published','archived'],true))throw new RuntimeException('This publication workflow is read-only.');
    $pdo->prepare("UPDATE research_publication_workflows SET owner_approved_by_user_id=?,owner_approved_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$viewer['id'],(int)$w['id']]);research_publication_event($pdo,(int)$w['id'],'owner_approved','user',(int)$viewer['id']);research_publication_evaluate($pdo,$viewer,$publicId);return research_publication_workflow_access($pdo,$viewer,$publicId)??$w;
}

function research_publication_sync_review(PDO $pdo,array $viewer,string $reviewPublic): void {
    if(!research_publications_ready($pdo))return;$q=$pdo->prepare("SELECT rpw.public_id FROM research_publication_workflows rpw JOIN research_reviews rr ON rr.id=rpw.current_review_id WHERE rr.public_id=? LIMIT 1");$q->execute([$reviewPublic]);$workflow=(string)($q->fetchColumn()?:'');if($workflow!=='')research_publication_evaluate($pdo,$viewer,$workflow);
}

function research_publication_document_changed(PDO $pdo,array $viewer,string $documentPublic): void {
    if(!research_publications_ready($pdo))return;$doc=research_agent_workspace_object($pdo,$viewer,$documentPublic,false);if(!$doc)return;$q=$pdo->prepare("SELECT public_id FROM research_publication_workflows WHERE document_object_id=? AND status IN ('in_review','changes_requested','approved')");$q->execute([(int)$doc['id']]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $public){try{research_publication_evaluate($pdo,$viewer,(string)$public);}catch(Throwable $ignored){}}
}

function research_publication_approval_snapshot(PDO $pdo,array $viewer,array $w): array {
    $evaluation=research_publication_evaluate($pdo,$viewer,(string)$w['public_id']);if(!$evaluation['pass'])throw new RuntimeException('Publication approval gates are not satisfied.');$review=$w['review_public_id']?research_review_access($pdo,$viewer,(string)$w['review_public_id']):null;$aggregate=$review?research_review_aggregate($pdo,$review):null;$threads=research_publication_threads($pdo,$viewer,(string)$w['public_id'],500);
    return ['workflow_public_id'=>$w['public_id'],'document_public_id'=>$w['document_public_id'],'document_revision_number'=>(int)$w['document_revision_number'],'document_revision_public_id'=>$w['document_revision_public_id'],'document_hash'=>$w['document_hash'],'review_public_id'=>$w['review_public_id'],'review_round'=>(int)$w['current_round'],'review_aggregate'=>$aggregate,'gates'=>$evaluation['gates'],'owner_approved_by_user_id'=>$w['owner_approved_by_user_id'],'owner_approved_at'=>$w['owner_approved_at'],'resolved_thread_count'=>count(array_filter($threads,fn($t)=>$t['status']==='resolved')),'approved_at'=>gmdate('Y-m-d H:i:s')];
}

function research_publication_document_snapshot(PDO $pdo,array $viewer,array $w): array {
    $q=$pdo->prepare("SELECT public_id,revision_number,title,content_html,plain_text,summary,edited_by_user_id,created_at FROM research_workspace_document_revisions WHERE document_object_id=? AND revision_number=? AND public_id=? LIMIT 1");$q->execute([(int)$w['document_object_id'],(int)$w['document_revision_number'],(string)$w['document_revision_public_id']]);$r=$q->fetch();if(!$r)throw new RuntimeException('Approved document revision is unavailable.');
    $hash=hash('sha256',json_encode(['document_public_id'=>$w['document_public_id'],'revision_number'=>(int)$r['revision_number'],'title'=>$r['title'],'content_html'=>$r['content_html'],'plain_text'=>$r['plain_text'],'summary'=>$r['summary']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));if(!hash_equals((string)$w['document_hash'],$hash))throw new RuntimeException('Approved document revision integrity check failed.');
    return ['public_id'=>$w['document_public_id'],'revision_public_id'=>$r['public_id'],'revision_number'=>(int)$r['revision_number'],'title'=>$r['title'],'summary'=>$r['summary'],'content_html'=>$r['content_html'],'plain_text'=>$r['plain_text'],'content_hash'=>$hash,'created_at'=>$r['created_at']];
}

function research_publication_distribution_targets(PDO $pdo,array $w): array {
    $q=$pdo->prepare("SELECT * FROM research_publication_distribution_targets WHERE workflow_id=? ORDER BY id");$q->execute([(int)$w['id']]);return $q->fetchAll()?:[];
}

function research_publication_distribute(PDO $pdo,array $viewer,array $w,array $publish): array {
    $targets=research_publication_distribution_targets($pdo,$w);$sent=0;$skipped=0;$failed=0;$seen=[];$report=['id'=>(int)$publish['report_id'],'public_id'=>$publish['public_id'],'title'=>$w['title']];
    $event=$pdo->prepare("INSERT INTO research_publication_distribution_events(public_id,workflow_id,report_version_id,target_type,target_user_id,target_team_id,status,detail) VALUES(?,?,?,?,?,?,?,?)");
    foreach($targets as $target){$type=(string)$target['target_type'];
      if($type==='subscribers'){$event->execute([ulid_like(),(int)$w['id'],(int)$publish['version_id'],'subscribers',null,null,'sent','Existing Living Research subscriber notification flow executed after publish.']);continue;}
      $users=[];
      if($type==='user'&&!empty($target['target_user_id'])){$q=$pdo->prepare("SELECT * FROM users WHERE id=? AND status='active' LIMIT 1");$q->execute([(int)$target['target_user_id']]);if($u=$q->fetch())$users[]=$u;}
      elseif($type==='team'&&!empty($target['target_team_id'])){$q=$pdo->prepare("SELECT u.* FROM team_members tm JOIN users u ON u.id=tm.user_id WHERE tm.team_id=? AND u.status='active'");$q->execute([(int)$target['target_team_id']]);$users=$q->fetchAll()?:[];}
      foreach($users as $u){$uid=(int)$u['id'];if(isset($seen[$uid]))continue;$seen[$uid]=true;if(!research_report_access($pdo,(string)$publish['public_id'],$u)){$event->execute([ulid_like(),(int)$w['id'],(int)$publish['version_id'],$type,$uid,$target['target_team_id']?:null,'skipped','Recipient does not have live access to the published report.']);$skipped++;continue;}try{notification_create($pdo,$uid,(int)$viewer['id'],'research_publication_published','research_report',(string)$publish['public_id'],'Published Research: '.$w['title'],['category'=>'research','dedupe_key'=>'research-publication:'.$w['public_id'].':v'.$publish['version_number'].':'.$uid,'group_key'=>'research-publication:'.$w['public_id'],'context'=>['workflow_public_id'=>$w['public_id'],'version_number'=>$publish['version_number']]]);$event->execute([ulid_like(),(int)$w['id'],(int)$publish['version_id'],$type,$uid,$target['target_team_id']?:null,'sent','In-app notification sent.']);$sent++;}catch(Throwable $e){$event->execute([ulid_like(),(int)$w['id'],(int)$publish['version_id'],$type,$uid,$target['target_team_id']?:null,'failed',mb_substr($e->getMessage(),0,1000)]);$failed++;}}
    }return ['sent'=>$sent,'skipped'=>$skipped,'failed'=>$failed];
}

function research_publication_publish(PDO $pdo,array $viewer,string $publicId): array {
    $w=research_publication_workflow_access($pdo,$viewer,$publicId);if(!$w)throw new RuntimeException('Publication workflow not found.');if(($viewer['role']??'')!=='admin'&&(int)$w['owner_user_id']!==(int)$viewer['id'])throw new RuntimeException('Only the project owner or administrator can publish approved Research.');if($w['status']==='published')throw new RuntimeException('This workflow is already published.');if($w['status']==='archived')throw new RuntimeException('Archived publication workflows are read-only.');
    $evaluation=research_publication_evaluate($pdo,$viewer,$publicId);$w=research_publication_workflow_access($pdo,$viewer,$publicId);if(!$evaluation['pass']||$w['status']!=='approved')throw new RuntimeException('Publication approval gates are not satisfied.');
    $project=project_access($pdo,(int)$viewer['id'],(string)$w['project_public_id']);if(!$project||!research_report_can_publish($project))throw new RuntimeException('Publishing authority is unavailable.');$doc=research_publication_document_snapshot($pdo,$viewer,$w);$approval=research_publication_approval_snapshot($pdo,$viewer,$w);
    $publish=research_report_publish($pdo,$project,$viewer,(string)$w['visibility'],(string)$w['title'],$w['summary']!==null?(string)$w['summary']:null,(array)$w['topics'],$doc,['workflow_id'=>(int)$w['id'],'document_object_id'=>(int)$w['document_object_id'],'document_revision_number'=>(int)$w['document_revision_number'],'document_revision_public_id'=>(string)$w['document_revision_public_id'],'approval_snapshot'=>$approval]);
    $fresh=research_publication_workflow_access($pdo,$viewer,$publicId);if(!$fresh||$fresh['status']!=='published')throw new RuntimeException('Publication transaction did not finalize the workflow.');
    try{$distribution=research_publication_distribute($pdo,$viewer,$fresh,$publish);}catch(Throwable $e){error_log('[Annotated publication distribution] '.$e->getMessage());$distribution=['sent'=>0,'skipped'=>0,'failed'=>1,'error'=>'Publication succeeded; one or more distribution operations need attention.'];}
    if(function_exists('research_intelligence_portfolio_publication_distributed')){try{research_intelligence_portfolio_publication_distributed($pdo,$viewer,$fresh,$publish,$distribution);}catch(Throwable $e){error_log('[Annotated portfolio publication continuity] '.$e->getMessage());}}
    return array_merge($publish,['distribution'=>$distribution,'workflow'=>$fresh]);
}

function research_publication_archive(PDO $pdo,array $viewer,string $publicId): array {
    $w=research_publication_workflow_access($pdo,$viewer,$publicId);if(!$w)throw new RuntimeException('Publication workflow not found.');if(!research_publication_can_manage($viewer,$w))throw new RuntimeException('You cannot archive this workflow.');if($w['status']==='published')throw new RuntimeException('Published workflows remain immutable publication records.');$pdo->prepare("UPDATE research_publication_workflows SET status='archived',archived_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$w['id']]);research_publication_event($pdo,(int)$w['id'],'archived','user',(int)$viewer['id']);return research_publication_workflow_access($pdo,$viewer,$publicId)??array_merge($w,['status'=>'archived']);
}

function research_publication_workflow_for_review(PDO $pdo,array $viewer,string $reviewPublic): ?array {
    $q=$pdo->prepare("SELECT rpw.public_id FROM research_publication_review_rounds rprr JOIN research_publication_workflows rpw ON rpw.id=rprr.workflow_id JOIN research_reviews rr ON rr.id=rprr.review_id WHERE rr.public_id=? ORDER BY rprr.round_number DESC LIMIT 1");$q->execute([trim($reviewPublic)]);$public=(string)($q->fetchColumn()?:'');return $public!==''?research_publication_workflow_detail($pdo,$viewer,$public):null;
}

function research_publication_distribution_events(PDO $pdo,array $viewer,string $workflowPublic,int $limit=200): array {
    $w=research_publication_workflow_access($pdo,$viewer,$workflowPublic);if(!$w)return [];$limit=max(1,min(500,$limit));$q=$pdo->prepare("SELECT rpde.*,u.display_name,u.username,t.name team_name FROM research_publication_distribution_events rpde LEFT JOIN users u ON u.id=rpde.target_user_id LEFT JOIN teams t ON t.id=rpde.target_team_id WHERE rpde.workflow_id=? ORDER BY rpde.id DESC LIMIT ".$limit);$q->execute([(int)$w['id']]);return $q->fetchAll()?:[];
}

function research_publication_workflow_detail(PDO $pdo,array $viewer,string $publicId): ?array {
    $w=research_publication_workflow_access($pdo,$viewer,$publicId);if(!$w)return null;if(!empty($w['review_public_id'])){$review=research_review_access($pdo,$viewer,(string)$w['review_public_id']);$w['review']=$review;if($review)$w['review_aggregate']=research_review_aggregate($pdo,$review);}$w['threads']=research_publication_threads($pdo,$viewer,$publicId,250);$q=$pdo->prepare("SELECT gate_type,status,detail,required_json,evaluated_at FROM research_publication_approval_gates WHERE workflow_id=? ORDER BY id");$q->execute([(int)$w['id']]);$w['gates']=$q->fetchAll()?:[];$q=$pdo->prepare("SELECT rprr.*,rr.public_id review_public_id FROM research_publication_review_rounds rprr JOIN research_reviews rr ON rr.id=rprr.review_id WHERE rprr.workflow_id=? ORDER BY round_number DESC");$q->execute([(int)$w['id']]);$w['rounds']=$q->fetchAll()?:[];$w['distribution_targets']=research_publication_distribution_targets($pdo,$w);$w['distribution_events']=research_publication_distribution_events($pdo,$viewer,$publicId,200);return $w;
}

function research_publication_list(PDO $pdo,array $viewer,string $status='all',int $limit=200): array {
    if(!research_publications_ready($pdo))return [];$limit=max(1,min(300,$limit));$params=[];$where=[];
    if(($viewer['role']??'')!=='admin'){$where[]='(rp.owner_user_id=? OR rpw.created_by_user_id=? OR EXISTS(SELECT 1 FROM team_members tm WHERE tm.team_id=rp.team_id AND tm.user_id=?))';$params[]=(int)$viewer['id'];$params[]=(int)$viewer['id'];$params[]=(int)$viewer['id'];}
    if($status!=='all'&&isset(research_publication_statuses()[$status])){$where[]='rpw.status=?';$params[]=$status;}$sql=$where?implode(' AND ',$where):'1=1';$q=$pdo->prepare("SELECT rpw.public_id FROM research_publication_workflows rpw JOIN research_projects rp ON rp.id=rpw.project_id WHERE $sql ORDER BY FIELD(rpw.status,'changes_requested','in_review','approved','draft','published','archived'),rpw.updated_at DESC LIMIT ".$limit);$q->execute($params);$out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$w=research_publication_workflow_access($pdo,$viewer,(string)$id);if($w)$out[]=$w;}return $out;
}

function research_publication_summary(PDO $pdo,array $viewer): array {
    $rows=research_publication_list($pdo,$viewer,'all',300);$counts=array_fill_keys(array_keys(research_publication_statuses()),0);$overdue=0;foreach($rows as $w){$counts[$w['status']]++;if(!empty($w['current_review_id'])){$r=research_review_access($pdo,$viewer,(string)$w['review_public_id']);if($r&&$r['status']==='open'&&!empty($r['due_at'])&&strtotime((string)$r['due_at'])<time())$overdue++;}}return ['counts'=>$counts,'overdue'=>$overdue,'total'=>count($rows)];
}

function research_publication_cognitive_observations(PDO $pdo,array $viewer,array &$items,int $limit=24): void {
    if(!research_publications_ready($pdo))return;foreach(array_slice(research_publication_list($pdo,$viewer,'all',80),0,$limit) as $w){$status=(string)$w['status'];$url='/research-publications.php?workflow='.rawurlencode((string)$w['public_id']);if($status==='changes_requested')cognitive_feed_add($items,['key'=>cognitive_feed_key('publication_changes_requested','research_publication',(string)$w['public_id'],(string)$w['updated_at']),'type'=>'publication_changes_requested','section'=>'needs_attention','priority'=>'high','created_at'=>$w['updated_at'],'score_extra'=>15,'title'=>'Publication changes requested','body'=>$w['title'].' needs another review round before it can publish.','meta'=>['workflow_id'=>$w['public_id']],'actions'=>[cognitive_feed_action_link('Open publication',$url)]]);
      elseif($status==='approved')cognitive_feed_add($items,['key'=>cognitive_feed_key('publication_ready','research_publication',(string)$w['public_id'],(string)$w['updated_at']),'type'=>'publication_ready','section'=>'needs_attention','priority'=>'high','created_at'=>$w['updated_at'],'score_extra'=>14,'title'=>'Research is approved for publication','body'=>$w['title'].' has satisfied its approval gates and is waiting for publication.','meta'=>['workflow_id'=>$w['public_id']],'actions'=>[cognitive_feed_action_link('Publish',$url)]]);
      elseif($status==='in_review'&&!empty($w['current_review_id'])){$r=research_review_access($pdo,$viewer,(string)$w['review_public_id']);if($r&&!empty($r['due_at'])&&strtotime((string)$r['due_at'])<time())cognitive_feed_add($items,['key'=>cognitive_feed_key('publication_review_overdue','research_publication',(string)$w['public_id'],(string)$r['due_at']),'type'=>'publication_review_overdue','section'=>'needs_attention','priority'=>'high','created_at'=>$w['updated_at'],'score_extra'=>13,'title'=>'Publication review is overdue','body'=>$w['title'].' review was due '.$r['due_at'].'.','meta'=>['workflow_id'=>$w['public_id'],'review_id'=>$w['review_public_id']],'actions'=>[cognitive_feed_action_link('Open review','/research-reviews.php?id='.rawurlencode((string)$w['review_public_id']))]]);}
    }
}
