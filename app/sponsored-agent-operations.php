<?php
declare(strict_types=1);
/**
 * Sponsored Research 4C: explicit human-confirmed Agent project operations.
 * The canonical Agent proposal/confirm/audit runtime and existing Sponsor
 * workspace/submission services remain the only mutation engines.
 */
function sponsored_agent_operation_capabilities(): array {
    return [
       'sponsored.project.post_update'=>[
           'label'=>'Post a private Sponsored Project progress update',
           'description'=>'After the assigned researcher explicitly confirms, append one attributed update to their own private sponsor/researcher project thread. No project-wide broadcasts or payout changes.',
           'arguments'=>['campaign_id'=>'exact attached Sponsored Project public ID','campaign_revision'=>'exact accepted campaign revision',
               'accepted_terms_hash'=>'exact accepted terms SHA-256','title'=>'string','body'=>'string',
               'progress_status'=>'update|started|blocked|ready_for_review optional',
               'milestone_position'=>'1-based accepted milestone index optional']
       ],
       'sponsored.project.submit'=>[
           'label'=>'Submit approved Sponsored Project research',
           'description'=>'After explicit researcher confirmation, submit existing owned Reports/Documents through the original immutable Sponsored Project submission and sponsor-review pipeline. Never create evidence, approve work, transfer funds or accept changed terms.',
           'arguments'=>['campaign_id'=>'exact attached Sponsored Project public ID','campaign_revision'=>'exact accepted campaign revision',
               'accepted_terms_hash'=>'exact accepted terms SHA-256','title'=>'string','summary'=>'string optional',
               'assets'=>'1..8 existing {type:system_report|document|report_version,public_id} objects cited in current Agent evidence',
               'supersedes_public_id'=>'latest own revision-requested submission ID optional']
       ]
    ];
}
function sponsored_agent_operation_clean(string $capability,array $input): array {
    if(!array_key_exists($capability,sponsored_agent_operation_capabilities()))
        throw new InvalidArgumentException('Unknown Sponsored Project Agent capability.');
    $campaign=trim((string)($input['campaign_id']??''));
    $hash=strtolower(trim((string)($input['accepted_terms_hash']??'')));
    $revision=filter_var($input['campaign_revision']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if($campaign===''||strlen($campaign)>128||!$revision||!preg_match('/^[a-f0-9]{64}$/',$hash))
        throw new InvalidArgumentException('Exact project, accepted revision and accepted terms hash are required.');
    $title=mb_substr(trim((string)($input['title']??'')),0,180);
    if($title==='')throw new InvalidArgumentException('A title is required.');
    $base=['campaign_id'=>$campaign,'campaign_revision'=>(int)$revision,'accepted_terms_hash'=>$hash,'title'=>$title];
    if($capability==='sponsored.project.post_update'){
        $body=trim((string)($input['body']??''));
        if($body===''||mb_strlen($body)>4000)throw new InvalidArgumentException('A bounded update body is required.');
        $status=(string)($input['progress_status']??'update');
        if(!in_array($status,['update','started','blocked','ready_for_review'],true))
            throw new InvalidArgumentException('Researchers may not mark shared milestones completed.');
        $position=trim((string)($input['milestone_position']??''));
        if($position!==''&&!preg_match('/^[1-9][0-9]?$/D',$position))
            throw new InvalidArgumentException('Invalid milestone position.');
        return $base+['body'=>$body,'scope'=>'participant','progress_status'=>$status,'milestone_position'=>$position];
    }
    $raw=$input['assets']??[];
    if(!is_array($raw)||count($raw)<1||count($raw)>8)throw new InvalidArgumentException('Choose 1 to 8 existing report/document assets.');
    $assets=[];$seen=[];
    foreach($raw as $asset){
        if(!is_array($asset))throw new InvalidArgumentException('Invalid evidence asset.');
        $type=(string)($asset['type']??'');$id=trim((string)($asset['public_id']??''));
        if(!in_array($type,['system_report','document','report_version'],true)||$id===''||strlen($id)>128)
            throw new InvalidArgumentException('Choose only existing supported research evidence.');
        $key=$type.':'.$id;if(isset($seen[$key]))continue;$seen[$key]=true;
        $assets[]=['type'=>$type,'public_id'=>$id];
    }
    if(!$assets)throw new InvalidArgumentException('Add at least one unique asset.');
    return $base+['summary'=>mb_substr(trim((string)($input['summary']??'')),0,2000),
       'assets'=>$assets,'supersedes_public_id'=>mb_substr(trim((string)($input['supersedes_public_id']??'')),0,128)];
}
function sponsored_agent_operation_access(PDO $pdo,array $viewer,array $project,array $args): ?array {
    if(!function_exists('sponsored_agent_awareness_access'))return null;
    $access=sponsored_agent_awareness_access($pdo,$viewer,(string)$args['campaign_id']);
    if(!$access)return null;
    $a=$access['assignment'];$p=$access['participation'];$c=$access['campaign'];
    if((int)$a['project_id']!==(int)$project['id']
       ||(string)$a['status']!=='active'
       ||(string)$p['status']!=='active'
       ||(int)$p['campaign_revision_accepted']!==(int)$c['current_revision']
       ||(int)$args['campaign_revision']!==(int)$p['campaign_revision_accepted']
       ||!hash_equals((string)$p['terms_hash'],(string)$args['accepted_terms_hash'])
       ||!in_array((string)$c['status'],['open','scheduled','under_review'],true))
        return null;
    $terms=sponsored_research_campaign_terms_latest($pdo,(int)$c['id']);
    if(!$terms||!hash_equals((string)$terms['terms_hash'],(string)$p['terms_hash']))
        return null;
    return $access;
}
function sponsored_agent_operation_validate(PDO $pdo,array $viewer,array $project,string $capability,array $args,array $refs): bool {
    if(!isset(sponsored_agent_operation_capabilities()[$capability]))return false;
    $seen=[];foreach($refs as $ref)if(is_array($ref))$seen[(string)($ref['type']??'').':'.(string)($ref['id']??'')]=true;
    if(!isset($seen['sponsored_project:'.$args['campaign_id']]))return false;
    $access=sponsored_agent_operation_access($pdo,$viewer,$project,$args);
    if(!$access)return false;
    if($capability==='sponsored.project.post_update'){
        try{sponsored_workspace_validate_update($access['workspace'],$viewer,$args);}catch(Throwable $e){return false;}
        return true;
    }
    if(empty($access['assignment']['agent_submit_enabled']))return false;
    foreach($args['assets'] as $asset){
        $kind=$asset['type']==='system_report'?'report':$asset['type'];
        if(!isset($seen[$kind.':'.$asset['public_id']]))return false;
    }
    $supersedes=(string)($args['supersedes_public_id']??'');
    if($supersedes!==''){
        $previous=sponsored_project_submission_get($pdo,$supersedes);
        if(!$previous||(int)$previous['assignment_id']!==(int)$access['assignment']['id']
           ||(string)$previous['status']!=='revision_requested')return false;
    }
    return true;
}
function sponsored_agent_operation_execute(PDO $pdo,array $viewer,array $project,string $capability,array $args): array {
    // Confirmation calls this only after provenance and current-state checks.
    // Independently recheck in the same transaction so no stale action can
    // bypass an intervening revocation, revised terms or Agent reassignment.
    $access=sponsored_agent_operation_access($pdo,$viewer,$project,$args);
    if(!$access)throw new RuntimeException('The Sponsored Project assignment or accepted terms changed before execution.');
    if($capability==='sponsored.project.post_update'){
        $post=sponsored_workspace_post($pdo,$access['workspace'],$viewer,$args);
        return ['type'=>'sponsored_project_update','public_id'=>(string)$post['public_id'],
            'label'=>(string)$args['title'],'url'=>'/sponsored-project-workspace.php?project='.rawurlencode((string)$args['campaign_id'])];
    }
    if($capability==='sponsored.project.submit'){
        if(empty($access['assignment']['agent_submit_enabled']))
            throw new RuntimeException('Researcher has not enabled Agent-assisted submission.');
        $submission=sponsored_project_submit($pdo,$viewer,(string)$args['campaign_id'],(array)$args['assets'],
            (string)$args['title'],(string)$args['summary'],true,(string)$args['supersedes_public_id']);
        return ['type'=>'sponsored_project_submission','public_id'=>(string)$submission['public_id'],
            'label'=>(string)$submission['title'],'status'=>(string)$submission['status'],
            'url'=>'/research-sponsored-projects.php#submission-'.rawurlencode((string)$submission['public_id'])];
    }
    throw new InvalidArgumentException('Unsupported Sponsored Project capability.');
}
