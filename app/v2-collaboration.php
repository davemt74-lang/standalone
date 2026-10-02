<?php
declare(strict_types=1);
/**
 * V2 Section 1 — owner-confirmed Agent collaboration roster.
 * Metadata only: never widens Research Project, Conversation, Team, Sponsor,
 * source-rights or memory access. Handoffs are explicitly out of scope.
 */
function v2_collaboration_ready(PDO $pdo): bool {
    return installer_table_exists($pdo,'v2_collaboration_plans')
        &&installer_table_exists($pdo,'v2_collaboration_assignments')
        &&installer_table_exists($pdo,'v2_collaboration_events');
}
function v2_collaboration_roles(): array {
    return ['lead'=>'Lead','evidence'=>'Evidence Gatherer','analyst'=>'Analyst',
        'verifier'=>'Verifier','publication_editor'=>'Publication Editor'];
}
function v2_collaboration_agent(PDO $pdo,array $owner,string $publicId): array {
    $agent=research_agent_access($pdo,$owner,trim($publicId));
    if(!$agent||(int)$agent['owner_user_id']!==(int)$owner['id'])
        throw new RuntimeException('You must own the Research Agent to add it to this collaboration.');
    if((string)$agent['status']!=='active'||(string)$agent['visibility']!=='private')
        throw new RuntimeException('Only active private Research Agents may be assigned.');
    $p=$pdo->prepare('SELECT owner_user_id,team_id,status FROM research_projects WHERE id=? LIMIT 1');
    $p->execute([(int)$agent['project_id']]);$project=$p->fetch(PDO::FETCH_ASSOC);
    if(!$project||(int)$project['owner_user_id']!==(int)$owner['id']
       ||(string)$project['status']!=='active'
       ||(int)($project['team_id']??0)!==(int)($agent['team_id']??0))
        throw new RuntimeException('The Agent must retain an active, consistently owned Research Project.');
    $s=$pdo->prepare("SELECT 1 FROM sponsored_research_agent_assignments
         WHERE research_agent_id=? AND status IN ('active','paused','completed') LIMIT 1");
    $s->execute([(int)$agent['id']]);
    if($s->fetchColumn())throw new RuntimeException('Sponsor-assigned Agents cannot join another collaboration.');
    if(!empty($agent['team_id'])){
        $t=$pdo->prepare("SELECT 1 FROM teams t JOIN team_members tm
           ON tm.team_id=t.id AND tm.user_id=? AND tm.role='owner'
           WHERE t.id=? AND t.owner_user_id=? LIMIT 1");
        $t->execute([(int)$owner['id'],(int)$agent['team_id'],(int)$owner['id']]);
        if(!$t->fetchColumn())throw new RuntimeException('Current Team owner membership is required.');
    }
    return $agent;
}
function v2_collaboration_compatible(array $lead,array $candidate): bool {
    return (int)$lead['owner_user_id']===(int)$candidate['owner_user_id']
        &&(int)($lead['team_id']??0)===(int)($candidate['team_id']??0)
        &&(int)$lead['id']!==(int)$candidate['id'];
}
function v2_collaboration_event(PDO $pdo,int $planId,int $actor,string $event,?int $agentId,array $detail=[]): void {
    $pdo->prepare('INSERT INTO v2_collaboration_events(plan_id,actor_user_id,event_type,agent_id,details_json)
                   VALUES(?,?,?,?,?)')
        ->execute([$planId,$actor,$event,$agentId,json_encode($detail,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
}
function v2_collaboration_create(PDO $pdo,array $owner,string $leadPublic): array {
    if(!v2_collaboration_ready($pdo))throw new RuntimeException('V2 collaboration migration is required.');
    $lead=v2_collaboration_agent($pdo,$owner,$leadPublic);
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT id FROM v2_collaboration_plans WHERE lead_agent_id=? FOR UPDATE');
        $q->execute([(int)$lead['id']]);
        if($q->fetchColumn())throw new RuntimeException('This Agent already leads a collaboration.');
        $public=ulid_like();
        $pdo->prepare("INSERT INTO v2_collaboration_plans(public_id,lead_agent_id,project_id,owner_user_id)
                        VALUES(?,?,?,?)")->execute([$public,(int)$lead['id'],(int)$lead['project_id'],(int)$owner['id']]);
        $planId=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO v2_collaboration_assignments(plan_id,agent_id,role,granted_by_user_id)
                       VALUES(?,?,'lead',?)")->execute([$planId,(int)$lead['id'],(int)$owner['id']]);
        v2_collaboration_event($pdo,$planId,(int)$owner['id'],'created',(int)$lead['id'],['scope'=>'roster_only']);
        $pdo->commit();
        return v2_collaboration_read($pdo,$owner,$public);
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function v2_collaboration_access(PDO $pdo,array $viewer,string $public,bool $lock=false): array {
    if(!v2_collaboration_ready($pdo))throw new RuntimeException('V2 collaboration migration is required.');
    $q=$pdo->prepare('SELECT * FROM v2_collaboration_plans WHERE public_id=?'.($lock?' FOR UPDATE':''));
    $q->execute([trim($public)]);$plan=$q->fetch(PDO::FETCH_ASSOC);
    if(!$plan)throw new RuntimeException('Collaboration not found.');
    // Viewing the roster is authorized against the CURRENT canonical lead
    // Agent membership, not an old role or event.
    $lead=$pdo->prepare('SELECT public_id FROM research_agents WHERE id=? LIMIT 1');
    $lead->execute([(int)$plan['lead_agent_id']]);$leadPublic=(string)$lead->fetchColumn();
    if($leadPublic===''||!research_agent_access($pdo,$viewer,$leadPublic))
        throw new RuntimeException('Collaboration not found.');
    if((int)$plan['owner_user_id']!==(int)$viewer['id']&&!empty($lock))
        throw new RuntimeException('Only the collaboration owner may modify its roster.');
    return $plan;
}
function v2_collaboration_read(PDO $pdo,array $viewer,string $public): array {
    $plan=v2_collaboration_access($pdo,$viewer,$public);
    $q=$pdo->prepare("SELECT ca.role,ca.status,ra.public_id agent_public_id,ra.name,ra.status agent_status,
             ra.visibility,ra.team_id,ra.owner_user_id,ra.project_id
             FROM v2_collaboration_assignments ca JOIN research_agents ra ON ra.id=ca.agent_id
             WHERE ca.plan_id=? ORDER BY FIELD(ca.role,'lead','evidence','analyst','verifier','publication_editor'),ca.id");
    $q->execute([(int)$plan['id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
    // A revoked Team member must not receive another Agent's private roster
    // merely because a historic assignment remains in the table.
    $visible=[];
    foreach($rows as $row){
        if((string)$row['status']==='removed')continue;
        if(research_agent_access($pdo,$viewer,(string)$row['agent_public_id'])) {
            $visible[]=['agent_public_id'=>$row['agent_public_id'],'name'=>$row['name'],
              'role'=>$row['role'],'status'=>$row['status'],
              'eligible_now'=>(string)$row['agent_status']==='active'
                &&(string)$row['visibility']==='private'
                &&(int)$row['owner_user_id']===(int)$plan['owner_user_id']];
        }
    }
    return ['public_id'=>$plan['public_id'],'status'=>$plan['status'],
       'revision'=>(int)$plan['revision'],'lead_project_id'=>(int)$plan['project_id'],
       'owner'=>(int)$plan['owner_user_id']===(int)$viewer['id'],
       'roster_only'=>true,'allows_cross_agent_data_access'=>false,'members'=>$visible];
}
function v2_collaboration_assign(PDO $pdo,array $owner,string $planPublic,string $agentPublic,string $role): array {
    if(!in_array($role,['evidence','analyst','verifier','publication_editor'],true))
        throw new InvalidArgumentException('Choose a permitted non-lead work role.');
    $pdo->beginTransaction();
    try{
        $plan=v2_collaboration_access($pdo,$owner,$planPublic,true);
        if((int)$plan['owner_user_id']!==(int)$owner['id']
            ||(string)$plan['status']!=='active')throw new RuntimeException('Only the owner may edit an active collaboration.');
        $q=$pdo->prepare('SELECT public_id FROM research_agents WHERE id=?');
        $q->execute([(int)$plan['lead_agent_id']]);
        $lead=v2_collaboration_agent($pdo,$owner,(string)$q->fetchColumn());
        // The plan's original scope may have changed since its creation.
        if((int)$plan['project_id']!==(int)$lead['project_id'])
            throw new RuntimeException('Collaboration scope has changed.');
        $agent=v2_collaboration_agent($pdo,$owner,$agentPublic);
        if(!v2_collaboration_compatible($lead,$agent))
            throw new RuntimeException('Agents must retain separate projects within the same owner and Team scope.');
        $num=$pdo->prepare("SELECT COUNT(*) FROM v2_collaboration_assignments WHERE plan_id=? AND status<>'removed'");
        $num->execute([(int)$plan['id']]);$count=(int)$num->fetchColumn();
        $existing=$pdo->prepare('SELECT * FROM v2_collaboration_assignments WHERE plan_id=? AND agent_id=?');
        $existing->execute([(int)$plan['id'],(int)$agent['id']]);$row=$existing->fetch(PDO::FETCH_ASSOC);
        if($row&&$row['role']===$role&&$row['status']==='active') {
            $pdo->commit();return v2_collaboration_read($pdo,$owner,$planPublic);
        }
        if((!$row||$row['status']==='removed')&&$count>=3)
            throw new RuntimeException('This initial pilot allows at most three Agents per collaboration.');
        if($row){
            $pdo->prepare("UPDATE v2_collaboration_assignments SET role=?,status='active',granted_by_user_id=? WHERE id=?")
                ->execute([$role,(int)$owner['id'],(int)$row['id']]);
        }else{
            $pdo->prepare("INSERT INTO v2_collaboration_assignments(plan_id,agent_id,role,granted_by_user_id) VALUES(?,?,?,?)")
                ->execute([(int)$plan['id'],(int)$agent['id'],$role,(int)$owner['id']]);
        }
        $event=$row&&$row['status']!=='removed'?'role_changed':'assigned';
        v2_collaboration_event($pdo,(int)$plan['id'],(int)$owner['id'],$event,(int)$agent['id'],['role'=>$role]);
        $pdo->prepare('UPDATE v2_collaboration_plans SET revision=revision+1 WHERE id=?')->execute([(int)$plan['id']]);
        $pdo->commit();return v2_collaboration_read($pdo,$owner,$planPublic);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function v2_collaboration_member_state(PDO $pdo,array $owner,string $public,string $memberPublic,string $state): array {
    if(!in_array($state,['paused','active','removed'],true))throw new InvalidArgumentException('Invalid member state.');
    $pdo->beginTransaction();
    try{
        $plan=v2_collaboration_access($pdo,$owner,$public,true);
        if((int)$plan['owner_user_id']!==(int)$owner['id'])throw new RuntimeException('Only owner may change this plan.');
        $q=$pdo->prepare('SELECT id FROM research_agents WHERE public_id=?');
        $q->execute([trim($memberPublic)]);$id=(int)$q->fetchColumn();
        if(!$id||$id===(int)$plan['lead_agent_id'])throw new RuntimeException('The Lead cannot be removed through member controls.');
        $q=$pdo->prepare('SELECT id,status FROM v2_collaboration_assignments WHERE plan_id=? AND agent_id=?');
        $q->execute([(int)$plan['id'],$id]);$member=$q->fetch(PDO::FETCH_ASSOC);
        if(!$member||$member['status']==='removed')throw new RuntimeException('Active membership is required.');
        if($state==='active'){
            if((string)$plan['status']!=='active')throw new RuntimeException('Resume the plan before activating a member.');
            $leadQ=$pdo->prepare('SELECT public_id FROM research_agents WHERE id=?');
            $leadQ->execute([(int)$plan['lead_agent_id']]);
            $lead=v2_collaboration_agent($pdo,$owner,(string)$leadQ->fetchColumn());
            $agent=v2_collaboration_agent($pdo,$owner,$memberPublic);
            if(!v2_collaboration_compatible($lead,$agent))throw new RuntimeException('Membership scope is no longer valid.');
        }
        if($member['status']!==$state){
            $pdo->prepare('UPDATE v2_collaboration_assignments SET status=? WHERE id=?')->execute([$state,(int)$member['id']]);
            v2_collaboration_event($pdo,(int)$plan['id'],(int)$owner['id'],$state==='active'?'resumed':($state==='removed'?'removed':'paused'),$id);
            $pdo->prepare('UPDATE v2_collaboration_plans SET revision=revision+1 WHERE id=?')->execute([(int)$plan['id']]);
        }
        $pdo->commit();return v2_collaboration_read($pdo,$owner,$public);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function v2_collaboration_plan_state(PDO $pdo,array $owner,string $public,string $state): array {
    if(!in_array($state,['active','paused'],true))throw new InvalidArgumentException('Invalid plan state.');
    $pdo->beginTransaction();
    try{
        $plan=v2_collaboration_access($pdo,$owner,$public,true);
        if((int)$plan['owner_user_id']!==(int)$owner['id']
           ||(string)$plan['status']==='archived')throw new RuntimeException('Only the owner may change this plan.');
        if($state==='active'){
            $q=$pdo->prepare('SELECT public_id FROM research_agents WHERE id=?');
            $q->execute([(int)$plan['lead_agent_id']]);v2_collaboration_agent($pdo,$owner,(string)$q->fetchColumn());
        }
        if($plan['status']!==$state){
            $pdo->prepare('UPDATE v2_collaboration_plans SET status=?,revision=revision+1 WHERE id=?')->execute([$state,(int)$plan['id']]);
            v2_collaboration_event($pdo,(int)$plan['id'],(int)$owner['id'],$state==='active'?'plan_resumed':'plan_paused',null);
        }
        $pdo->commit();return v2_collaboration_read($pdo,$owner,$public);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function v2_collaboration_for_lead(PDO $pdo,array $viewer,string $leadPublic): ?array {
    if(!v2_collaboration_ready($pdo))return null;
    $agent=research_agent_access($pdo,$viewer,$leadPublic);
    if(!$agent)return null;
    $q=$pdo->prepare('SELECT public_id FROM v2_collaboration_plans WHERE lead_agent_id=? LIMIT 1');
    $q->execute([(int)$agent['id']]);$public=$q->fetchColumn();
    return $public?v2_collaboration_read($pdo,$viewer,(string)$public):null;
}
function v2_collaboration_assignable_agents(PDO $pdo,array $owner,string $leadPublic): array {
    $lead=v2_collaboration_agent($pdo,$owner,$leadPublic);
    $q=$pdo->prepare("SELECT public_id FROM research_agents WHERE owner_user_id=?
       AND id<>? AND status='active' AND visibility='private'
       AND team_id ".(!empty($lead['team_id'])?'= ?':'IS NULL')." ORDER BY name,id LIMIT 60");
    $params=[(int)$owner['id'],(int)$lead['id']];
    if(!empty($lead['team_id']))$params[]=(int)$lead['team_id'];
    $q->execute($params);$out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){
        try{$agent=v2_collaboration_agent($pdo,$owner,(string)$id);
            if(v2_collaboration_compatible($lead,$agent))$out[]=['public_id'=>$agent['public_id'],'name'=>$agent['name']];
        }catch(RuntimeException $e){}
    }
    return $out;
}
