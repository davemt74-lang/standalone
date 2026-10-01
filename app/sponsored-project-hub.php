<?php
declare(strict_types=1);
/**
 * Canonical Sponsored Project Hub: access controlled by EXISTING Sponsor Account
 * membership or approved Research Account participation, never by project URL,
 * invite alone, an unrelated Team, or a publicly visible campaign.
 *
 * This hub only shares sponsor-approved project updates and workflow metadata.
 * Research Agent Desktop, Library and raw working documents retain their
 * original owner/Team ACLs; sponsors access deliverables through submission.
 */
function sponsored_project_hub_ready(PDO $pdo): bool {
    return sponsored_projects_ready($pdo)
        &&installer_table_exists($pdo,'sponsored_research_project_updates');
}
function sponsored_project_hub_sample(PDO $pdo,string $projectPublic): ?array {
    foreach(sponsored_project_sample_projects($pdo) as $sample){
        if(hash_equals((string)$sample['public_id'],$projectPublic))
            return ['role'=>'sample','project'=>$sample,'participation'=>null,'write'=>false,'sample'=>true];
    }
    return null;
}
function sponsored_project_hub_access(PDO $pdo,?array $viewer,string $projectPublic): ?array {
    $projectPublic=trim($projectPublic);
    if($projectPublic===''||mb_strlen($projectPublic)>128)return null;
    if(str_starts_with($projectPublic,'sample-'))
        return sponsored_project_hub_sample($pdo,$projectPublic);
    if(!$viewer||!sponsored_project_hub_ready($pdo))return null;
    $campaign=sponsored_research_campaign_by_public($pdo,$projectPublic);
    if(!$campaign)return null;
    try {
        $managed=sponsored_research_campaign_require_manage($pdo,$viewer,$projectPublic);
        return ['role'=>'sponsor','project'=>$managed,'participation'=>null,
            'write'=>!in_array((string)$managed['status'],['completed','cancelled','archived'],true),
            'sample'=>false];
    }catch(Throwable $ignored){/* A sponsor-authority failure never grants team or public access. */}
    try{research_account_require_approved($pdo,$viewer);}catch(Throwable $ignored){return null;}
    $p=sponsored_research_participation_get($pdo,(int)$campaign['id'],(int)$viewer['id']);
    if(!$p||!in_array((string)$p['status'],['active','completed'],true))return null;
    $canWrite=(string)$p['status']==='active'
      && (int)$p['campaign_revision_accepted']===(int)$campaign['current_revision']
      && (string)$campaign['status']==='open';
    return ['role'=>'researcher','project'=>$campaign,'participation'=>$p,
        'write'=>$canWrite,'sample'=>false];
}
function sponsored_project_hub_updates(PDO $pdo,array $access,int $limit=70): array {
    if(!empty($access['sample'])){
        return [
          ['subject'=>'Research plan prepared','body'=>'Example: define the research method, evidence standards and expected deliverables.',
             'author_name'=>'Sample Research Agent','audience'=>'participants','update_type'=>'progress','created_at'=>'Demo content'],
          ['subject'=>'Example review checkpoint','body'=>'Example: organize the evidence for sponsor review.',
             'author_name'=>'Sample Sponsor','audience'=>'participants','update_type'=>'announcement','created_at'=>'Demo content'],
        ];
    }
    $limit=max(1,min(100,$limit));
    $role=(string)$access['role'];$id=(int)$access['project']['id'];
    $where=$role==='sponsor'?'':' AND u.audience=\'participants\'';
    $q=$pdo->prepare("SELECT u.public_id,u.audience,u.update_type,u.subject,u.body,u.campaign_revision,u.created_at,
      p.display_name author_name,p.username author_username
      FROM sponsored_research_project_updates u
      JOIN users p ON p.id=u.author_user_id
      WHERE u.campaign_id=?".$where." ORDER BY u.created_at DESC,u.id DESC LIMIT ".$limit);
    $q->execute([$id]);return $q->fetchAll()?:[];
}
function sponsored_project_hub_publish(PDO $pdo,array $viewer,string $projectPublic,array $input): array {
    if(!sponsored_project_hub_ready($pdo))throw new RuntimeException('Project Hub requires the latest database upgrade.');
    $first=sponsored_project_hub_access($pdo,$viewer,$projectPublic);
    if(!$first||!empty($first['sample'])||empty($first['write']))
        throw new RuntimeException('You do not have permission to post to this project.');
    $subject=trim((string)($input['subject']??''));
    $body=trim((string)($input['body']??''));
    if($subject===''||mb_strlen($subject)>180)throw new InvalidArgumentException('Subject is required and must be 180 characters or fewer.');
    if($body===''||mb_strlen($body)>12000)throw new InvalidArgumentException('A project update of at most 12,000 characters is required.');
    $kind=(string)($input['update_type']??'progress');
    if(!in_array($kind,['progress','question','answer','announcement','decision'],true))
        throw new InvalidArgumentException('Unknown project update type.');
    $audience=(string)($input['audience']??'participants');
    if(!in_array($audience,['participants','sponsor_only'],true))
        throw new InvalidArgumentException('Unknown project update audience.');
    if($audience==='sponsor_only'&&$first['role']!=='sponsor')
        throw new RuntimeException('Only the sponsor can post internal sponsor updates.');
    if($first['role']==='researcher'&&in_array($kind,['announcement','decision'],true))
        throw new RuntimeException('Sponsor announcements and decisions require sponsor authority.');
    return app_with_advisory_lock($pdo,'sponsored-project-hub',(int)$first['project']['id'],
      function()use($pdo,$viewer,$projectPublic,$subject,$body,$kind,$audience){
        $fresh=sponsored_project_hub_access($pdo,$viewer,$projectPublic);
        if(!$fresh||!empty($fresh['sample'])||empty($fresh['write']))
            throw new RuntimeException('Project permission changed before publishing this update.');
        $campaign=$fresh['project'];
        $pdo->beginTransaction();
        try {
            $public=ulid_like();
            $pdo->prepare('INSERT INTO sponsored_research_project_updates(public_id,campaign_id,author_user_id,audience,update_type,subject,body,campaign_revision) VALUES(?,?,?,?,?,?,?,?)')
              ->execute([$public,(int)$campaign['id'],(int)$viewer['id'],$audience,$kind,$subject,$body,(int)$campaign['current_revision']]);
            // Existing canonical sponsor campaign event ledger provides attribution.
            sponsored_research_campaign_event($pdo,(int)$campaign['id'],(int)$viewer['id'],
               'project_hub_update',['public_id'=>$public,'update_type'=>$kind,'audience'=>$audience,
                 'campaign_revision'=>(int)$campaign['current_revision']]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return ['public_id'=>$public,'subject'=>$subject,'audience'=>$audience,'update_type'=>$kind];
      },5);
}
/** All project progress derives from existing assignments/submissions; never invent a duplicate task state. */
function sponsored_project_hub_progress(PDO $pdo,array $access): array {
    if(!empty($access['sample']))return ['assignments'=>0,'submitted'=>0,'under_review'=>0,'accepted'=>0,'revisions'=>0];
    $campaign=(int)$access['project']['id'];
    $researcher=$access['role']==='researcher'
      ?(int)$access['participation']['researcher_user_id']:null;
    $filter=$researcher!==null?' AND researcher_user_id=?':'';
    $params=$researcher!==null?[$campaign,$researcher]:[$campaign];
    $q=$pdo->prepare("SELECT COUNT(*) FROM sponsored_research_agent_assignments WHERE campaign_id=?".$filter);
    $q->execute($params);$assignments=(int)$q->fetchColumn();
    $q=$pdo->prepare("SELECT status,COUNT(*) total FROM sponsored_research_project_submissions WHERE campaign_id=?".$filter." GROUP BY status");
    $q->execute($params);
    $r=['assignments'=>$assignments,'submitted'=>0,'under_review'=>0,'accepted'=>0,'revisions'=>0];
    foreach($q->fetchAll()?:[] as $row){
        $status=(string)$row['status'];$count=(int)$row['total'];
        if($status==='submitted')$r['submitted']+=$count;
        if($status==='under_review')$r['under_review']+=$count;
        if($status==='accepted')$r['accepted']+=$count;
        if($status==='revision_requested')$r['revisions']+=$count;
    }
    return $r;
}
function sponsored_project_hub_contributions(PDO $pdo,array $access,int $limit=35): array {
    if(!empty($access['sample']))return [];
    $limit=max(1,min(100,$limit));$id=(int)$access['project']['id'];
    $filter=$access['role']==='researcher'?' AND s.researcher_user_id=?':'';
    $params=[$id];
    if($access['role']==='researcher')$params[]=(int)$access['participation']['researcher_user_id'];
    $q=$pdo->prepare("SELECT s.public_id,s.title,s.status,s.revision_number,s.submitted_at,
       u.display_name contributor_name,ra.name agent_name
       FROM sponsored_research_project_submissions s
       JOIN users u ON u.id=s.researcher_user_id
       JOIN research_agents ra ON ra.id=s.research_agent_id
       WHERE s.campaign_id=?".$filter." ORDER BY s.submitted_at DESC,s.id DESC LIMIT ".$limit);
    $q->execute($params);return $q->fetchAll()?:[];
}
