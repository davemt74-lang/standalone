<?php
declare(strict_types=1);
/**
 * Section 4D: bounded notification projection over existing Sponsored Projects.
 * The existing Research Automation worker invokes this; no second scheduler,
 * new notification table, Team ACL or automatic Sponsored Project write action.
 */
function sponsored_agent_proactive_window(string $deadline,?DateTimeImmutable $now=null): ?string {
    $now=$now??new DateTimeImmutable('now',new DateTimeZone('UTC'));
    try{$due=new DateTimeImmutable($deadline,new DateTimeZone('UTC'));}catch(Throwable $e){return null;}
    $remaining=$due->getTimestamp()-$now->getTimestamp();
    if($remaining<=0||$remaining>72*3600)return null;
    return $remaining<=24*3600?'24h':'72h';
}
function sponsored_agent_proactive_ready(PDO $pdo): bool {
    foreach(['sponsored_research_campaigns','sponsored_research_agent_assignments',
        'sponsored_research_participations','sponsored_project_updates','notifications'] as $table)
        if(!installer_table_exists($pdo,$table))return false;
    return function_exists('notification_create')&&function_exists('sponsored_agent_awareness_access');
}
function sponsored_agent_proactive_user(PDO $pdo,int $id,array &$cache): ?array {
    if($id<=0)return null;
    if(!array_key_exists($id,$cache)){
        $q=$pdo->prepare("SELECT * FROM users WHERE id=? AND status='active' LIMIT 1");
        $q->execute([$id]);$cache[$id]=$q->fetch()?:null;
    }
    return $cache[$id];
}
function sponsored_agent_proactive_scan(PDO $pdo,int $limit=100,?DateTimeImmutable $now=null): array {
    $result=['deadlines'=>0,'blockers'=>0,'revisions'=>0];
    if(!sponsored_agent_proactive_ready($pdo))return $result;
    $limit=max(1,min(200,$limit));
    $now=$now??new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $stamp=$now->format('Y-m-d H:i:s');
    $maxDue=$now->modify('+72 hours')->format('Y-m-d H:i:s');
    $users=[];
    // The original, exact assignment/participation relationship must still
    // be active and the accepted terms must be the currently published terms.
    $q=$pdo->prepare("SELECT c.id campaign_id,c.public_id campaign_public_id,c.title,c.submission_deadline,
       a.public_id assignment_public_id,a.researcher_user_id,ra.public_id agent_public_id
      FROM sponsored_research_campaigns c
      JOIN sponsored_research_agent_assignments a ON a.campaign_id=c.id AND a.status='active'
      JOIN sponsored_research_participations p ON p.id=a.participation_id
        AND p.researcher_user_id=a.researcher_user_id AND p.status='active'
        AND p.campaign_revision_accepted=c.current_revision
      JOIN research_agents ra ON ra.id=a.research_agent_id AND ra.status='active'
      WHERE c.status IN ('open','scheduled','under_review')
        AND c.submission_deadline>? AND c.submission_deadline<=?
      ORDER BY c.submission_deadline,a.id LIMIT ".$limit);
    $q->execute([$stamp,$maxDue]);
    foreach($q->fetchAll()?:[] as $row){
        $viewer=sponsored_agent_proactive_user($pdo,(int)$row['researcher_user_id'],$users);
        if(!$viewer)continue;
        $access=sponsored_agent_awareness_access($pdo,$viewer,(string)$row['campaign_public_id'],(string)$row['agent_public_id']);
        if(!$access||($access['assignment']['status']??'')!=='active'||($access['participation']['status']??'')!=='active')continue;
        $terms=sponsored_research_campaign_terms_latest($pdo,(int)$row['campaign_id']);
        if(!$terms||(int)$terms['id']!==(int)$access['participation']['terms_id'])continue;
        $window=sponsored_agent_proactive_window((string)$row['submission_deadline'],$now);
        if(!$window)continue;
        $dedupe='sponsored-deadline:'.$row['assignment_public_id'].':'.strtotime((string)$row['submission_deadline']).':'.$window;
        if(notification_create($pdo,(int)$viewer['id'],null,'research_sponsored_deadline',
            'sponsored_project',(string)$row['campaign_public_id'],
            'Your accepted Sponsored Project has a submission deadline approaching ('.$window.').',[
              'category'=>'research','dedupe_key'=>$dedupe,
              'group_key'=>'sponsored-project:'.$row['campaign_public_id'],
              'context'=>['assignment_public_id'=>(string)$row['assignment_public_id'],
                'research_agent_public_id'=>(string)$row['agent_public_id'],'window'=>$window]
            ]))$result['deadlines']++;
    }
    // A blocker exists only while no later ready/completed checkpoint supersedes it
    // for that same participant. Sponsor notifications never contain private post text.
    $blockedSince=$now->modify('-7 days')->format('Y-m-d H:i:s');
    $q=$pdo->prepare("SELECT up.id,up.public_id,up.campaign_id,up.participant_user_id,
       c.public_id campaign_public_id,c.account_id
      FROM sponsored_project_updates up
      JOIN sponsored_research_campaigns c ON c.id=up.campaign_id
      JOIN sponsored_research_agent_assignments a ON a.campaign_id=c.id
        AND a.researcher_user_id=up.participant_user_id AND a.status='active'
      JOIN sponsored_research_participations p ON p.id=a.participation_id
        AND p.researcher_user_id=up.participant_user_id AND p.status='active'
      WHERE up.scope='participant' AND up.actor_role='researcher' AND up.progress_status='blocked'
        AND up.actor_user_id=up.participant_user_id
        AND up.created_at>=? AND c.status IN ('open','scheduled','under_review')
        AND NOT EXISTS (SELECT 1 FROM sponsored_project_updates newer
           WHERE newer.campaign_id=up.campaign_id AND newer.participant_user_id=up.participant_user_id
             AND newer.id>up.id AND newer.progress_status IN ('ready_for_review','completed'))
      ORDER BY up.id DESC LIMIT ".$limit);
    $q->execute([$blockedSince]);
    foreach($q->fetchAll()?:[] as $row){
        $managers=$pdo->prepare("SELECT DISTINCT u.* FROM account_members am JOIN users u
          ON u.id=am.user_id AND u.status='active' WHERE am.account_id=? LIMIT 50");
        $managers->execute([(int)$row['account_id']]);
        foreach($managers->fetchAll()?:[] as $manager){
            $authorized=sponsored_workspace_access($pdo,$manager,(string)$row['campaign_public_id']);
            if(!$authorized||($authorized['role']??'')!=='sponsor')continue;
            $key='sponsored-blocker:'.$row['public_id'].':'.(int)$manager['id'];
            if(notification_create($pdo,(int)$manager['id'],(int)$row['participant_user_id'],
              'research_sponsored_blocker','sponsored_project',(string)$row['campaign_public_id'],
              'A researcher reported a blocker in your Sponsored Project.',[
                'category'=>'research','dedupe_key'=>$key,
                'group_key'=>'sponsored-blockers:'.$row['campaign_public_id'],
                'context'=>['source_update_public_id'=>(string)$row['public_id'],'recipient_role'=>'sponsor']
              ]))$result['blockers']++;
        }
    }
    // Sponsor review already emits this exact type/key. This is only an
    // idempotent recovery projection if the original notification is missing.
    $q=$pdo->query("SELECT s.public_id submission_public_id,s.researcher_user_id,c.id campaign_id,
       c.public_id campaign_public_id,ra.public_id agent_public_id
      FROM sponsored_research_project_submissions s
      JOIN sponsored_research_agent_assignments a ON a.id=s.assignment_id AND a.status='active'
      JOIN sponsored_research_campaigns c ON c.id=s.campaign_id
      JOIN research_agents ra ON ra.id=s.research_agent_id AND ra.status='active'
      WHERE s.status='revision_requested' AND c.status IN ('open','scheduled','under_review')
      ORDER BY s.id DESC LIMIT ".$limit);
    foreach($q->fetchAll()?:[] as $row){
        $viewer=sponsored_agent_proactive_user($pdo,(int)$row['researcher_user_id'],$users);
        if(!$viewer)continue;
        $access=sponsored_agent_awareness_access($pdo,$viewer,(string)$row['campaign_public_id'],(string)$row['agent_public_id']);
        if(!$access||($access['assignment']['status']??'')!=='active')continue;
        $terms=sponsored_research_campaign_terms_latest($pdo,(int)$row['campaign_id']);
        if(!$terms||(int)$terms['id']!==(int)$access['participation']['terms_id'])continue;
        if(notification_create($pdo,(int)$viewer['id'],null,'research_sponsored_revision_requested',
          'sponsored_project_submission',(string)$row['submission_public_id'],
          'A revision was requested for your Sponsored Project submission.',[
            'category'=>'research',
            'dedupe_key'=>'sponsored-review:'.$row['submission_public_id'].':revision_requested',
            'context'=>['campaign_public_id'=>(string)$row['campaign_public_id'],
              'research_agent_public_id'=>(string)$row['agent_public_id']]
          ]))$result['revisions']++;
    }
    return $result;
}
