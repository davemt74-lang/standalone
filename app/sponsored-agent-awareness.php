<?php
declare(strict_types=1);
/**
 * Section 4B: bounded read-only Sponsored Project context for the exact
 * researcher/accepted participation/personally-owned assigned Research Agent.
 *
 * This adapter does not grant Chat actions, edit terms, copy private Agent files,
 * create notifications, or share another participant's journal.
 */
function sponsored_agent_awareness_access(PDO $pdo,array $viewer,string $campaignPublic,string $expectedAgentPublic=''): ?array {
    if((int)($viewer['id']??0)<=0||trim($campaignPublic)===''||!function_exists('sponsored_workspace_ready')||!sponsored_workspace_ready($pdo))return null;
    // The workspace policy enforces Research Account approval, exact accepted
    // participation/assignment pairing and current membership on every read.
    $access=sponsored_workspace_access($pdo,$viewer,$campaignPublic);
    if(!$access||($access['role']??'')!=='researcher')return null;
    $a=(array)($access['assignment']??[]);
    $p=(array)($access['participation']??[]);
    $c=(array)($access['campaign']??[]);
    $agentPublic=(string)($a['research_agent_public_id']??'');
    if($agentPublic===''||($expectedAgentPublic!==''&&!hash_equals($expectedAgentPublic,$agentPublic))
      ||(int)($a['researcher_user_id']??0)!==(int)$viewer['id']
      ||(int)($a['participation_id']??0)!==(int)($p['id']??-1)
      ||(int)($p['researcher_user_id']??0)!==(int)$viewer['id'])return null;
    $agent=research_agent_access($pdo,$viewer,$agentPublic);
    if(!$agent||(int)($agent['owner_user_id']??0)!==(int)$viewer['id']
      ||!empty($agent['team_id'])||!empty($agent['project_team_id'])
      ||!in_array((string)($agent['status']??''),['active','paused'],true)
      ||(int)($agent['id']??0)!==(int)($a['research_agent_id']??-1))return null;
    return ['campaign'=>$c,'participation'=>$p,'assignment'=>$a,'agent'=>$agent,'workspace'=>$access];
}
function sponsored_agent_awareness_project_options(PDO $pdo,array $viewer,int $limit=12): array {
    if((int)($viewer['id']??0)<=0||!function_exists('sponsored_workspace_ready')||!sponsored_workspace_ready($pdo))return [];
    try{research_account_require_approved($pdo,$viewer);}catch(Throwable $ignored){return [];}
    $q=$pdo->prepare("SELECT c.public_id FROM sponsored_research_campaigns c
      JOIN sponsored_research_agent_assignments a ON a.campaign_id=c.id AND a.researcher_user_id=?
      JOIN sponsored_research_participations p ON p.id=a.participation_id AND p.researcher_user_id=?
      WHERE a.status IN ('active','paused','completed') AND p.status IN ('active','completed')
      ORDER BY a.id DESC LIMIT ".max(1,min(40,$limit)));
    $q->execute([(int)$viewer['id'],(int)$viewer['id']]);
    $out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $public){
        $access=sponsored_agent_awareness_access($pdo,$viewer,(string)$public);
        if($access)$out[]=['type'=>'sponsored_project','public_id'=>(string)$public,'label'=>mb_substr((string)$access['campaign']['title'],0,90)];
    }
    return $out;
}
function sponsored_agent_awareness_agent_context(PDO $pdo,array $viewer,array $agent,int $updateLimit=6): ?array {
    if((int)($viewer['id']??0)<=0||empty($agent['id'])||empty($agent['public_id'])
      ||(int)($agent['owner_user_id']??0)!==(int)$viewer['id']||!empty($agent['team_id']))return null;
    if(!function_exists('sponsored_project_agent_context'))return null;
    $assignment=sponsored_project_agent_context($pdo,(int)$agent['id']);
    if(!$assignment)return null;
    return sponsored_agent_awareness_project_context($pdo,$viewer,(string)$assignment['campaign_public_id'],(string)$agent['public_id'],$updateLimit);
}
function sponsored_agent_awareness_project_context(PDO $pdo,array $viewer,string $publicId,string $agentPublic='',int $updateLimit=6): ?array {
    $access=sponsored_agent_awareness_access($pdo,$viewer,$publicId,$agentPublic);
    if(!$access)return null;
    $c=$access['campaign'];$p=$access['participation'];$a=$access['assignment'];
    $acceptedRevision=(int)($p['campaign_revision_accepted']??0);
    // Read the signed-off revision, not the latest sponsor-edited brief.
    $vq=$pdo->prepare('SELECT config_json,config_hash FROM sponsored_research_campaign_versions WHERE campaign_id=? AND revision_number=? LIMIT 1');
    $vq->execute([(int)$c['id'],$acceptedRevision]);$version=$vq->fetch();
    if(!$version)return null;
    $snapshot=json_decode((string)$version['config_json'],true);
    if(!is_array($snapshot)||!hash_equals((string)$version['config_hash'],sponsored_research_campaign_hash($snapshot)))return null;
    $specs=sponsored_project_builder_normalize($snapshot['project_specs']??[], $snapshot['submission_deadline']??null);
    // Only the terms actually accepted by this participant, not the latest
    // draft/sponsor-only terms. A later revision is flagged, never accepted implicitly.
    $acceptedTerms=trim((string)($p['terms_text']??''));
    $acceptedHash=(string)($p['terms_hash']??'');
    $currentRevision=(int)($c['current_revision']??0);
    $latestTerms=sponsored_research_campaign_terms_latest($pdo,(int)$c['id']);
    $termsChanged=$latestTerms!==null&&!hash_equals((string)($latestTerms['terms_hash']??''),$acceptedHash);
    $stale=$acceptedRevision!==$currentRevision||$termsChanged;
    $notes=sponsored_workspace_recent($pdo,$access['workspace'],$viewer,max(1,min(8,$updateLimit)));
    $lines=[
      '[SPONSORED PROJECT '.(string)$c['public_id'].']',
      'Title: '.mb_substr((string)($c['title']??''),0,200),
      'Accepted brief: '.mb_substr((string)($snapshot['brief']??''),0,2700),
      'Accepted objective: '.mb_substr((string)($snapshot['objective']??''),0,1600),
      'Campaign status: '.(string)$c['status'],
      'Campaign revision: '.$currentRevision,
      'Accepted revision: '.$acceptedRevision.($stale?' (STALE: do not assume updated scope or terms are accepted)':''),
      'Accepted terms version: '.(string)($p['terms_version']??'unavailable'),
      'Accepted campaign config hash: '.(string)$version['config_hash'],
      'Accepted terms hash: '.$acceptedHash,
      'Your assignment status: '.(string)$a['status'],
      'Agent: '.(string)$a['research_agent_public_id'],
      'Agent submission permitted: '.(!empty($a['agent_submit_enabled'])?'yes':'no'),
      'Accepted submission deadline: '.(string)($snapshot['submission_deadline']??'not specified'),
      'Accepted review deadline: '.(string)($snapshot['review_deadline']??'not specified'),
      'Audience: '.$specs['target_audience'],
      'Geography: '.$specs['geography'],
      'In-scope: '.mb_substr((string)$specs['scope_in'],0,2200),
      'Out-of-scope: '.mb_substr((string)$specs['scope_out'],0,1800),
      'Methods: '.implode(', ',$specs['methods']),
    ];
    if($acceptedTerms!=='')$lines[]='Your explicitly accepted terms: '.mb_substr($acceptedTerms,0,3600);
    foreach(array_slice((array)($snapshot['questions']??[]),0,12) as $q){
        $question=is_array($q)?(string)($q['question']??''):(string)$q;
        if($question!=='')$lines[]='Research question: '.mb_substr($question,0,500);
    }
    foreach(array_slice($specs['deliverables'],0,12) as $d)
        $lines[]='Deliverable: '.mb_substr((string)$d['title'],0,160).' · '.(string)$d['format'].' · '.mb_substr((string)$d['acceptance_criteria'],0,450).' · due '.(string)($d['due_date']??'unspecified');
    foreach(array_slice($specs['milestones'],0,20) as $m)
        $lines[]='Planned milestone: '.mb_substr((string)$m['title'],0,160).' · '.mb_substr((string)$m['success_criteria'],0,450).' · due '.(string)($m['due_date']??'unspecified');
    $lines[]='Latest YOUR submission: '.(string)($a['completed_submission_id']??'not completed');
    if(function_exists('sponsored_project_agent_context')){
        $latest=sponsored_project_agent_context($pdo,(int)$a['research_agent_id']);
        if($latest&&(int)($latest['campaign_id']??0)===(int)$c['id']){
            $lines[]='Submission status: '.(string)($latest['latest_submission_status']??'not submitted');
            $lines[]='Latest submission ID: '.(string)($latest['latest_submission_public_id']??'none');
            if(!empty($latest['review_note']))$lines[]='Your latest review note: '.mb_substr((string)$latest['review_note'],0,1200);
        }
    }
    $lines[]='Visible project updates (sponsor-wide and YOUR private thread only):';
    foreach(array_reverse($notes) as $note){
        $lines[]=mb_substr((string)($note['created_at']??''),0,25).' · '.(string)($note['scope']??'').'/'.(string)($note['actor_role']??'').': '.
          mb_substr((string)($note['title']??''),0,160).' — '.mb_substr((string)($note['body']??''),0,650);
    }
    $lines[]='This is read-only context. Never claim to submit, publish, accept terms, change milestones, notify the sponsor, or make compensation changes without an explicit confirmed application action.';
    $text=mb_substr(implode("\n",$lines),0,24000);
    return ['type'=>'sponsored_project','public_id'=>(string)$c['public_id'],'label'=>(string)$c['title'],
      'text'=>$text,'refs'=>[['type'=>'sponsored_project','id'=>(string)$c['public_id']],
        ['type'=>'research_project','id'=>(string)$a['project_public_id']]],
      'meta'=>['agent_public_id'=>(string)$a['research_agent_public_id'],
         'campaign_revision'=>$currentRevision,'accepted_revision'=>$acceptedRevision,
         'accepted_terms_hash'=>$acceptedHash,'accepted_terms_version'=>(int)($p['terms_version']??0),
         'requires_reacceptance'=>$stale,'accepted_config_hash'=>(string)$version['config_hash'],'read_only'=>true]];
}
