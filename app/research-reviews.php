<?php
declare(strict_types=1);

function research_reviews_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_reviews')&&installer_table_exists($pdo,'research_review_assignments')&&installer_table_exists($pdo,'research_review_responses')&&installer_table_exists($pdo,'research_review_comments')&&installer_table_exists($pdo,'research_review_events');}
    catch(Throwable $e){return false;}
}

function research_review_decisions(): array {
    return ['approve'=>'Approve','request_changes'=>'Request changes','disagree'=>'Disagree','abstain'=>'Abstain'];
}

function research_review_event(PDO $pdo,int $reviewId,string $type,?int $actorId,array $payload=[]): void {
    $pdo->prepare('INSERT INTO research_review_events(review_id,event_type,actor_user_id,payload_json) VALUES(?,?,?,?)')
      ->execute([$reviewId,mb_substr($type,0,48),$actorId,$payload?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
}

function research_review_subject(PDO $pdo,array $viewer,string $type,string $publicId): ?array {
    $type=strtolower(trim($type));$publicId=trim($publicId);if($publicId==='')return null;
    if($type==='claim'){
        $q=$pdo->prepare("SELECT rc.*,rp.public_id project_public_id,rp.title project_title FROM research_claims rc JOIN research_projects rp ON rp.id=rc.project_id WHERE rc.public_id=? LIMIT 1");$q->execute([$publicId]);$r=$q->fetch();if(!$r||!project_access($pdo,(int)$viewer['id'],(string)$r['project_public_id']))return null;
        $hash=hash('sha256',json_encode([$r['statement'],$r['claim_type'],$r['status'],$r['resolution_note']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return ['type'=>'claim','public_id'=>$publicId,'project_id'=>(int)$r['project_id'],'project_public_id'=>$r['project_public_id'],'project_title'=>$r['project_title'],'title'=>'Claim: '.mb_substr((string)$r['statement'],0,190),'hash'=>$hash,'version_label'=>'Claim updated '.(string)$r['updated_at'],'url'=>'/research-claim.php?id='.rawurlencode($publicId),'summary'=>(string)$r['statement']];
    }
    if($type==='finding'){
        $q=$pdo->prepare("SELECT rf.*,rp.public_id project_public_id,rp.title project_title FROM research_findings rf JOIN research_projects rp ON rp.id=rf.project_id WHERE rf.public_id=? LIMIT 1");$q->execute([$publicId]);$r=$q->fetch();if(!$r||!project_access($pdo,(int)$viewer['id'],(string)$r['project_public_id']))return null;
        $cq=$pdo->prepare('SELECT rc.public_id,fc.relationship,fc.position FROM finding_claims fc JOIN research_claims rc ON rc.id=fc.claim_id WHERE fc.finding_id=? ORDER BY fc.position,rc.public_id');$cq->execute([$r['id']]);$claims=$cq->fetchAll();
        $hash=hash('sha256',json_encode([$r['title'],$r['summary'],$r['status'],$claims],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return ['type'=>'finding','public_id'=>$publicId,'project_id'=>(int)$r['project_id'],'project_public_id'=>$r['project_public_id'],'project_title'=>$r['project_title'],'title'=>'Finding: '.(string)$r['title'],'hash'=>$hash,'version_label'=>'Finding updated '.(string)$r['updated_at'],'url'=>'/research-finding.php?id='.rawurlencode($publicId),'summary'=>(string)$r['summary']];
    }
    if($type==='report_version'){
        $q=$pdo->prepare("SELECT rv.*,rr.public_id report_public_id,rr.current_version_id,rp.id project_id,rp.public_id project_public_id,rp.title project_title FROM research_report_versions rv JOIN research_reports rr ON rr.id=rv.report_id JOIN research_projects rp ON rp.id=rr.project_id WHERE rv.public_id=? LIMIT 1");$q->execute([$publicId]);$r=$q->fetch();if(!$r||!project_access($pdo,(int)$viewer['id'],(string)$r['project_public_id']))return null;
        return ['type'=>'report_version','public_id'=>$publicId,'project_id'=>(int)$r['project_id'],'project_public_id'=>$r['project_public_id'],'project_title'=>$r['project_title'],'title'=>'Report v'.(int)$r['version_number'].': '.(string)$r['title'],'hash'=>(string)$r['snapshot_hash'],'version_label'=>'Report version '.(int)$r['version_number'],'url'=>'/research-report.php?id='.rawurlencode((string)$r['report_public_id']).'&v='.(int)$r['version_number'],'summary'=>(string)($r['summary']??''),'report_public_id'=>$r['report_public_id'],'version_number'=>(int)$r['version_number'],'version_id'=>(int)$r['id'],'current_version_id'=>(int)($r['current_version_id']??0)];
    }
    if($type==='agent_action'){
        if(!agent_actions_ready($pdo))return null;$q=$pdo->prepare("SELECT aap.*,rp.public_id project_public_id,rp.title project_title,c.public_id conversation_public_id FROM agent_action_proposals aap JOIN research_projects rp ON rp.id=aap.project_id JOIN conversations c ON c.id=aap.conversation_id WHERE aap.public_id=? LIMIT 1");$q->execute([$publicId]);$r=$q->fetch();if(!$r||!project_access($pdo,(int)$viewer['id'],(string)$r['project_public_id']))return null;
        $args=json_decode((string)$r['arguments_json'],true)?:[];$hash=hash('sha256',json_encode([$r['capability_key'],$args,$r['project_state_hash'],$r['status']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return ['type'=>'agent_action','public_id'=>$publicId,'project_id'=>(int)$r['project_id'],'project_public_id'=>$r['project_public_id'],'project_title'=>$r['project_title'],'title'=>'Agent proposal: '.(string)$r['capability_key'],'hash'=>$hash,'version_label'=>'Proposal state '.(string)$r['status'],'url'=>'/home.php?conversation='.rawurlencode((string)$r['conversation_public_id']).'#agent-chat','summary'=>json_encode($args,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'proposal_status'=>$r['status']];
    }
    return null;
}

function research_review_subject_state(PDO $pdo,array $viewer,array $review): array {
    $subject=research_review_subject($pdo,$viewer,(string)$review['subject_type'],(string)$review['subject_public_id']);
    if(!$subject)return ['available'=>false,'stale'=>true,'reason'=>'The reviewed Research object is no longer accessible.','subject'=>null];
    if($review['subject_type']==='report_version'){
        $newer=!empty($subject['current_version_id'])&&(int)$subject['current_version_id']!==(int)$subject['version_id'];
        return ['available'=>true,'stale'=>$newer,'reason'=>$newer?'A newer report version has been published. This review remains pinned to '.$review['subject_version_label'].'.':'','subject'=>$subject];
    }
    $stale=!hash_equals((string)$review['subject_hash'],(string)$subject['hash']);
    return ['available'=>true,'stale'=>$stale,'reason'=>$stale?'The Research object changed after this review was requested. Start an updated review before relying on prior responses.':'','subject'=>$subject];
}

function research_review_eligible_reviewers(PDO $pdo,array $viewer,string $projectPublic): array {
    $project=project_access($pdo,(int)$viewer['id'],$projectPublic);if(!$project)return [];
    $ids=[(int)$project['owner_user_id']=>true];
    if(!empty($project['team_id'])){$q=$pdo->prepare("SELECT user_id FROM team_members WHERE team_id=?");$q->execute([$project['team_id']]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)$ids[(int)$id]=true;}
    if(!$ids)return [];$in=implode(',',array_map('intval',array_keys($ids)));$q=$pdo->query("SELECT id,public_id,username,display_name,status FROM users WHERE id IN ($in) AND status='active' ORDER BY display_name,username");return $q->fetchAll();
}

function research_review_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_reviews_ready($pdo))return null;
    $q=$pdo->prepare("SELECT rr.*,rp.public_id project_public_id,rp.title project_title,rp.owner_user_id,rp.team_id,u.display_name requester_name,u.username requester_username
      FROM research_reviews rr JOIN research_projects rp ON rp.id=rr.project_id JOIN users u ON u.id=rr.requested_by_user_id WHERE rr.public_id=? LIMIT 1");$q->execute([trim($publicId)]);$r=$q->fetch();if(!$r)return null;
    if(!project_access($pdo,(int)$viewer['id'],(string)$r['project_public_id']))return null;
    $state=research_review_subject_state($pdo,$viewer,$r);$r['subject_state']=$state;$r['is_stale']=$state['stale'];$r['stale_reason']=$state['reason'];$r['subject']=$state['subject'];
    return $r;
}

function research_review_assignments(PDO $pdo,array $review): array {
    $q=$pdo->prepare("SELECT rra.*,u.public_id user_public_id,u.username,u.display_name,rrr.public_id response_public_id,rrr.decision,rrr.comment response_comment,rrr.created_at response_created_at
      FROM research_review_assignments rra JOIN users u ON u.id=rra.reviewer_user_id LEFT JOIN research_review_responses rrr ON rrr.id=rra.latest_response_id
      WHERE rra.review_id=? ORDER BY u.display_name,u.username");$q->execute([$review['id']]);return $q->fetchAll();
}

function research_review_comments(PDO $pdo,array $review,int $limit=200): array {
    $limit=max(1,min(500,$limit));$q=$pdo->prepare("SELECT rrc.*,u.public_id user_public_id,u.username,u.display_name FROM research_review_comments rrc JOIN users u ON u.id=rrc.user_id WHERE rrc.review_id=? ORDER BY rrc.id ASC LIMIT ".$limit);$q->execute([$review['id']]);return $q->fetchAll();
}

function research_review_aggregate(PDO $pdo,array $review): array {
    $assignments=research_review_assignments($pdo,$review);$counts=['approve'=>0,'request_changes'=>0,'disagree'=>0,'abstain'=>0,'pending'=>0];$responses=[];
    foreach($assignments as $a){$decision=(string)($a['decision']??'');if(isset($counts[$decision])){$counts[$decision]++;$responses[]=['reviewer_user_id'=>(int)$a['reviewer_user_id'],'reviewer'=>(string)($a['display_name']?:$a['username']),'decision'=>$decision,'response_public_id'=>$a['response_public_id'],'comment'=>$a['response_comment'],'created_at'=>$a['response_created_at']];}else $counts['pending']++;}
    $assigned=count($assignments);$responded=$assigned-$counts['pending'];$consensus='awaiting_reviewers';
    if($counts['request_changes']>0)$consensus='changes_requested';elseif($counts['disagree']>0)$consensus='unresolved_objection';elseif($assigned>0&&$counts['approve']===$assigned)$consensus='unanimous_approval';elseif($assigned>0&&$responded===$assigned)$consensus='mixed_review';
    return ['assigned'=>$assigned,'responded'=>$responded,'counts'=>$counts,'consensus'=>$consensus,'responses'=>$responses,'assignments'=>$assignments];
}

function research_review_can_manage(array $viewer,array $review): bool {
    if(($viewer['role']??'')==='admin'||(int)$review['requested_by_user_id']===(int)$viewer['id']||(int)$review['owner_user_id']===(int)$viewer['id'])return true;
    return false;
}

function research_review_is_assigned(PDO $pdo,array $viewer,array $review): bool {
    $q=$pdo->prepare('SELECT 1 FROM research_review_assignments WHERE review_id=? AND reviewer_user_id=? LIMIT 1');$q->execute([$review['id'],$viewer['id']]);return (bool)$q->fetchColumn();
}

function research_review_notify(PDO $pdo,int $userId,?int $actorId,string $type,array $review,string $body,array $context=[]): void {
    notification_create($pdo,$userId,$actorId,$type,'research_review',(string)$review['public_id'],$body,['category'=>'research','dedupe_key'=>$type.':'.$review['public_id'].':'.hash('sha256',$body.'|'.(string)$actorId.'|'.(string)($context['event_public_id']??'')),'group_key'=>'research_review:'.$review['public_id'],'context'=>array_merge(['project_public_id'=>$review['project_public_id']??null],$context)]);
}

function research_review_record_outcome(PDO $pdo,array $viewer,array $review,string $decision,string $title,string $summary,array $extra=[]): void {
    if(!function_exists('research_outcome_try_record')||!research_outcomes_ready($pdo))return;
    research_outcome_try_record($pdo,$viewer,['event_type'=>'research_review','decision_type'=>$decision,'source_type'=>'research_review','source_public_id'=>$review['public_id'],'project_public_id'=>$review['project_public_id'],'object_type'=>$review['subject_type'],'object_public_id'=>$review['subject_public_id'],'title'=>$title,'summary'=>$summary,'refs'=>[['type'=>'project','public_id'=>$review['project_public_id'],'role'=>'context']], 'metadata'=>$extra,'occurred_at'=>date('Y-m-d H:i:s'),'dedupe_key'=>'review:'.$review['public_id'].':'.$decision.':'.($extra['response_public_id']??$extra['completed_at']??ulid_like())]);
}

function research_review_create(PDO $pdo,array $viewer,string $subjectType,string $subjectPublic,array $reviewerIds,?string $dueAt=null,string $instructions=''): array {
    if(!research_reviews_ready($pdo))throw new RuntimeException('Collaborative Research Review requires the Phase 21 database upgrade.');
    $subject=research_review_subject($pdo,$viewer,$subjectType,$subjectPublic);if(!$subject)throw new RuntimeException('Research object is unavailable.');
    $project=project_access($pdo,(int)$viewer['id'],(string)$subject['project_public_id']);if(!$project||!project_can_write($project))throw new RuntimeException('Write access to the Research project is required to request review.');
    $eligible=[];foreach(research_review_eligible_reviewers($pdo,$viewer,(string)$project['public_id']) as $candidate)$eligible[(int)$candidate['id']]=$candidate;
    $reviewerIds=array_values(array_unique(array_map('intval',$reviewerIds)));$reviewerIds=array_values(array_filter($reviewerIds,fn($id)=>$id>0&&$id!==(int)$viewer['id']&&isset($eligible[$id])));
    if(!$reviewerIds)throw new InvalidArgumentException('Choose at least one other current project collaborator to review this Research.');
    $due=null;if($dueAt!==null&&trim($dueAt)!==''){$ts=strtotime($dueAt);if($ts===false||$ts<=time())throw new InvalidArgumentException('Review deadline must be in the future.');$due=date('Y-m-d H:i:s',$ts);}
    $public=ulid_like();$title=mb_substr((string)$subject['title'],0,255);$instructions=mb_substr(trim($instructions),0,8000);
    $pdo->beginTransaction();try{
        $pdo->prepare("INSERT INTO research_reviews(public_id,project_id,requested_by_user_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,instructions,due_at) VALUES(?,?,?,?,?,?,?,?,?,?)")
          ->execute([$public,$subject['project_id'],$viewer['id'],$subject['type'],$subject['public_id'],$subject['hash'],$subject['version_label'],$title,$instructions?:null,$due]);
        $id=(int)$pdo->lastInsertId();$ins=$pdo->prepare('INSERT INTO research_review_assignments(review_id,reviewer_user_id,assigned_by_user_id) VALUES(?,?,?)');foreach($reviewerIds as $rid)$ins->execute([$id,$rid,$viewer['id']]);
        research_review_event($pdo,$id,'requested',(int)$viewer['id'],['reviewer_user_ids'=>$reviewerIds,'subject_hash'=>$subject['hash'],'due_at'=>$due]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $review=research_review_access($pdo,$viewer,$public);foreach($reviewerIds as $rid)research_review_notify($pdo,$rid,(int)$viewer['id'],'research_review_requested',$review,'Review requested: '.$review['title'],['due_at'=>$due]);
    return $review;
}

function research_review_respond(PDO $pdo,array $viewer,string $publicId,string $decision,string $comment=''): array {
    $review=research_review_access($pdo,$viewer,$publicId);if(!$review)throw new RuntimeException('Review is unavailable.');if($review['status']!=='open')throw new RuntimeException('This review is no longer open.');if(!research_review_is_assigned($pdo,$viewer,$review))throw new RuntimeException('You are not assigned to this review.');if($review['is_stale'])throw new RuntimeException('This review is stale. Start an updated review before responding.');
    if(!isset(research_review_decisions()[$decision]))throw new InvalidArgumentException('Invalid review response.');$comment=mb_substr(trim($comment),0,12000);if(in_array($decision,['request_changes','disagree'],true)&&$comment==='')throw new InvalidArgumentException('Explain requested changes or disagreement.');
    $public=ulid_like();$pdo->beginTransaction();try{
        $pdo->prepare('INSERT INTO research_review_responses(public_id,review_id,reviewer_user_id,decision,comment,subject_hash) VALUES(?,?,?,?,?,?)')->execute([$public,$review['id'],$viewer['id'],$decision,$comment?:null,$review['subject_hash']]);$responseId=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE research_review_assignments SET latest_response_id=?,responded_at=NOW() WHERE review_id=? AND reviewer_user_id=?')->execute([$responseId,$review['id'],$viewer['id']]);
        research_review_event($pdo,(int)$review['id'],'responded',(int)$viewer['id'],['response_public_id'=>$public,'decision'=>$decision]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if((int)$review['requested_by_user_id']!==(int)$viewer['id'])research_review_notify($pdo,(int)$review['requested_by_user_id'],(int)$viewer['id'],'research_review_response',$review,(string)($viewer['display_name']?:$viewer['username']).' responded to '.$review['title'].': '.(research_review_decisions()[$decision]??$decision),['event_public_id'=>$public]);
    research_review_record_outcome($pdo,$viewer,$review,$decision,'Research review response: '.(research_review_decisions()[$decision]??$decision),$comment,['response_public_id'=>$public]);
    return research_review_access($pdo,$viewer,$publicId)??[];
}

function research_review_comment(PDO $pdo,array $viewer,string $publicId,string $body): array {
    $review=research_review_access($pdo,$viewer,$publicId);if(!$review)throw new RuntimeException('Review is unavailable.');if($review['status']!=='open')throw new RuntimeException('Completed or cancelled reviews are read-only.');$body=mb_substr(trim($body),0,12000);if($body==='')throw new InvalidArgumentException('Review comment cannot be empty.');
    $public=ulid_like();$pdo->prepare('INSERT INTO research_review_comments(public_id,review_id,user_id,body) VALUES(?,?,?,?)')->execute([$public,$review['id'],$viewer['id'],$body]);research_review_event($pdo,(int)$review['id'],'commented',(int)$viewer['id'],['comment_public_id'=>$public]);
    if((int)$review['requested_by_user_id']!==(int)$viewer['id'])research_review_notify($pdo,(int)$review['requested_by_user_id'],(int)$viewer['id'],'research_review_comment',$review,'New comment on '.$review['title'],['event_public_id'=>$public]);
    return ['public_id'=>$public,'body'=>$body];
}

function research_review_complete(PDO $pdo,array $viewer,string $publicId): array {
    $review=research_review_access($pdo,$viewer,$publicId);if(!$review)throw new RuntimeException('Review is unavailable.');if($review['status']!=='open')throw new RuntimeException('Review is no longer open.');if(!research_review_can_manage($viewer,$review))throw new RuntimeException('Only the requester, project owner, or administrator can complete this review.');if($review['is_stale'])throw new RuntimeException('This review is stale. Start an updated review instead of completing it.');
    $agg=research_review_aggregate($pdo,$review);$snapshot=['subject_hash'=>$review['subject_hash'],'subject_version_label'=>$review['subject_version_label'],'consensus'=>$agg['consensus'],'counts'=>$agg['counts'],'assigned'=>$agg['assigned'],'responded'=>$agg['responded'],'responses'=>$agg['responses'],'completed_by_user_id'=>(int)$viewer['id'],'completed_at'=>date('Y-m-d H:i:s')];
    $pdo->prepare("UPDATE research_reviews SET status='completed',completed_at=NOW(),completion_json=?,updated_at=NOW() WHERE id=? AND status='open'")->execute([json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$review['id']]);research_review_event($pdo,(int)$review['id'],'completed',(int)$viewer['id'],$snapshot);
    foreach($agg['assignments'] as $a)if((int)$a['reviewer_user_id']!==(int)$viewer['id'])research_review_notify($pdo,(int)$a['reviewer_user_id'],(int)$viewer['id'],'research_review_completed',$review,'Review completed: '.$review['title'],['consensus'=>$agg['consensus']]);
    research_review_record_outcome($pdo,$viewer,$review,'completed','Research review completed',$agg['consensus'],['completed_at'=>$snapshot['completed_at'],'consensus'=>$agg['consensus'],'counts'=>$agg['counts']]);
    return research_review_access($pdo,$viewer,$publicId)??[];
}

function research_review_cancel(PDO $pdo,array $viewer,string $publicId): bool {
    $review=research_review_access($pdo,$viewer,$publicId);if(!$review)return false;if($review['status']!=='open')return false;if(!research_review_can_manage($viewer,$review))throw new RuntimeException('You cannot cancel this review.');
    $q=$pdo->prepare("UPDATE research_reviews SET status='cancelled',cancelled_at=NOW(),updated_at=NOW() WHERE id=? AND status='open'");$q->execute([$review['id']]);if($q->rowCount()!==1)return false;research_review_event($pdo,(int)$review['id'],'cancelled',(int)$viewer['id']);return true;
}

function research_review_restart(PDO $pdo,array $viewer,string $publicId,?string $dueAt=null): array {
    $review=research_review_access($pdo,$viewer,$publicId);if(!$review)throw new RuntimeException('Review is unavailable.');if(!research_review_can_manage($viewer,$review))throw new RuntimeException('You cannot restart this review.');
    $assignments=research_review_assignments($pdo,$review);$ids=array_map(fn($a)=>(int)$a['reviewer_user_id'],$assignments);$new=research_review_create($pdo,$viewer,(string)$review['subject_type'],(string)$review['subject_public_id'],$ids,$dueAt,(string)($review['instructions']??''));
    if($review['status']==='open'){$pdo->prepare("UPDATE research_reviews SET status='cancelled',cancelled_at=NOW(),updated_at=NOW() WHERE id=? AND status='open'")->execute([$review['id']]);research_review_event($pdo,(int)$review['id'],'superseded',(int)$viewer['id'],['new_review_public_id'=>$new['public_id']]);}
    research_review_event($pdo,(int)$new['id'],'restarted_from',(int)$viewer['id'],['previous_review_public_id'=>$review['public_id']]);return $new;
}

function research_review_list(PDO $pdo,array $viewer,string $scope='all',int $limit=100): array {
    if(!research_reviews_ready($pdo))return [];$limit=max(1,min(300,$limit));$params=[];$where='1=1';
    if($scope==='assigned'){$where='EXISTS(SELECT 1 FROM research_review_assignments rra WHERE rra.review_id=rr.id AND rra.reviewer_user_id=?)';$params[]=$viewer['id'];}
    elseif($scope==='requested'){$where='rr.requested_by_user_id=?';$params[]=$viewer['id'];}
    elseif($scope==='completed'){$where="rr.status='completed'";}
    elseif($scope==='open'){$where="rr.status='open'";}
    $q=$pdo->prepare("SELECT rr.public_id FROM research_reviews rr WHERE $where ORDER BY rr.status='open' DESC,COALESCE(rr.due_at,'9999-12-31') ASC,rr.created_at DESC LIMIT ".$limit);$q->execute($params);$out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$r=research_review_access($pdo,$viewer,(string)$id);if(!$r)continue;$agg=research_review_aggregate($pdo,$r);$r['aggregate']=$agg;$r['assigned_to_viewer']=research_review_is_assigned($pdo,$viewer,$r);$r['overdue']=$r['status']==='open'&&!empty($r['due_at'])&&strtotime((string)$r['due_at'])<time();$out[]=$r;}return $out;
}

function research_review_project_summary(PDO $pdo,array $viewer,string $projectPublic): array {
    $project=project_access($pdo,(int)$viewer['id'],$projectPublic);if(!$project||!research_reviews_ready($pdo))return ['open'=>0,'assigned_to_me'=>0,'changes_requested'=>0,'unresolved_objections'=>0,'overdue'=>0,'stale'=>0,'items'=>[]];
    $q=$pdo->prepare("SELECT public_id FROM research_reviews WHERE project_id=? AND status='open' ORDER BY COALESCE(due_at,'9999-12-31'),created_at DESC LIMIT 30");$q->execute([$project['id']]);$out=['open'=>0,'assigned_to_me'=>0,'changes_requested'=>0,'unresolved_objections'=>0,'overdue'=>0,'stale'=>0,'items'=>[]];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$r=research_review_access($pdo,$viewer,(string)$id);if(!$r)continue;$a=research_review_aggregate($pdo,$r);$out['open']++;if(research_review_is_assigned($pdo,$viewer,$r))$out['assigned_to_me']++;if($a['consensus']==='changes_requested')$out['changes_requested']++;if($a['consensus']==='unresolved_objection')$out['unresolved_objections']++;if(!empty($r['due_at'])&&strtotime((string)$r['due_at'])<time())$out['overdue']++;if($r['is_stale'])$out['stale']++;$out['items'][]=['public_id'=>$r['public_id'],'title'=>$r['title'],'consensus'=>$a['consensus'],'due_at'=>$r['due_at'],'is_stale'=>$r['is_stale'],'assigned_to_viewer'=>research_review_is_assigned($pdo,$viewer,$r)];}
    return $out;
}

function research_review_context(PDO $pdo,array $viewer,string $projectPublic,int $limit=12): array {
    $s=research_review_project_summary($pdo,$viewer,$projectPublic);if(!$s['open'])return ['text'=>'','refs'=>[],'summary'=>$s];$lines=['[COLLABORATIVE RESEARCH REVIEW]','Open reviews: '.$s['open'].'; assigned to you: '.$s['assigned_to_me'].'; changes requested: '.$s['changes_requested'].'; unresolved objections: '.$s['unresolved_objections'].'; overdue: '.$s['overdue'].'; stale: '.$s['stale'].'.'];$refs=[];
    foreach(array_slice($s['items'],0,$limit) as $item){$lines[]='- '.$item['title'].' · '.$item['consensus'].($item['is_stale']?' · stale':'').($item['due_at']?' · due '.$item['due_at']:'').' [REVIEW '.$item['public_id'].']';$refs[]=['type'=>'research_review','id'=>$item['public_id']];}
    return ['text'=>implode("\n",$lines),'refs'=>$refs,'summary'=>$s];
}

function research_review_agent_handoff(PDO $pdo,array $viewer,string $publicId): ?array {
    $r=research_review_access($pdo,$viewer,$publicId);if(!$r)return null;$agg=research_review_aggregate($pdo,$r);$context=[['type'=>'research','public_id'=>$r['project_public_id']]];
    $prompt='Summarize this collaborative Research review without voting or deciding for the reviewers. Review: '.$r['title'].'. Current state: '.$agg['consensus'].'. Explain the areas of agreement, requested changes, unresolved objections, pending reviewers, and the evidence or Research context that appears relevant. If the review is stale, explain why. Do not approve, reject, close, or modify the review.';
    return ['prompt'=>$prompt,'context'=>$context,'review'=>$r,'aggregate'=>$agg];
}

function research_review_cognitive_observations(PDO $pdo,array $viewer,array &$items,int $limit=24): void {
    if(!research_reviews_ready($pdo))return;$reviews=research_review_list($pdo,$viewer,'open',$limit);
    foreach($reviews as $r){$agg=$r['aggregate'];$assigned=$r['assigned_to_viewer'];$myPending=false;foreach($agg['assignments'] as $a)if((int)$a['reviewer_user_id']===(int)$viewer['id']&&empty($a['decision']))$myPending=true;
        if(!$assigned&&(int)$r['requested_by_user_id']!==(int)$viewer['id']&&!research_review_can_manage($viewer,$r))continue;
        $type='review_pending';$section='next_up';$priority='medium';$title='Research review is in progress';$body=$r['title'].' · '.str_replace('_',' ',$agg['consensus']);
        if($r['is_stale']){$type='review_stale';$section='needs_attention';$priority='high';$title='Research review is based on older Research';$body=$r['stale_reason'];}
        elseif($r['overdue']&&$myPending){$type='review_overdue';$section='needs_attention';$priority='high';$title='Research review is overdue';$body=$r['title'].' was due '.$r['due_at'].'.';}
        elseif($agg['consensus']==='changes_requested'&&(int)$r['requested_by_user_id']===(int)$viewer['id']){$type='review_changes_requested';$section='needs_attention';$priority='high';$title='Changes were requested';}
        elseif($agg['consensus']==='unresolved_objection'&&(int)$r['requested_by_user_id']===(int)$viewer['id']){$type='review_objection';$section='needs_attention';$priority='high';$title='Research review has an unresolved objection';}
        elseif($myPending){$type='review_requested';$section='next_up';$title='Research review assigned to you';}
        else continue;
        $actions=[cognitive_feed_action_link('Open review','/research-reviews.php?id='.rawurlencode((string)$r['public_id'])),cognitive_feed_action_agent('Ask Agent','Summarize this Research review, disagreements, requested changes and relevant evidence. Do not vote or decide for reviewers.',[['type'=>'research','public_id'=>$r['project_public_id']]])];
        cognitive_feed_add($items,['key'=>cognitive_feed_key($type,'research_review',(string)$r['public_id'],(string)($r['updated_at']??$r['created_at'])),'type'=>$type,'section'=>$section,'priority'=>$priority,'created_at'=>$r['updated_at']??$r['created_at'],'score_extra'=>$priority==='high'?14:5,'title'=>$title,'body'=>$body,'meta'=>['review_id'=>$r['public_id'],'project'=>$r['project_title'],'consensus'=>$agg['consensus'],'due_at'=>$r['due_at']],'actions'=>$actions]);
    }
}

function research_review_digest(PDO $pdo,array $viewer,string $projectPublic): string {
    $s=research_review_project_summary($pdo,$viewer,$projectPublic);$lines=['Collaborative review digest.','Open: '.$s['open'].'. Assigned to you: '.$s['assigned_to_me'].'. Changes requested: '.$s['changes_requested'].'. Unresolved objections: '.$s['unresolved_objections'].'. Overdue: '.$s['overdue'].'. Stale: '.$s['stale'].'.'];foreach(array_slice($s['items'],0,15) as $i)$lines[]='- '.$i['title'].' · '.str_replace('_',' ',$i['consensus']).($i['is_stale']?' · stale':'').($i['due_at']?' · due '.$i['due_at']:'');return implode("\n",$lines);
}
function research_review_input_hash(PDO $pdo,array $viewer,string $projectPublic): string {
    $s=research_review_project_summary($pdo,$viewer,$projectPublic);return hash('sha256',json_encode($s,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

