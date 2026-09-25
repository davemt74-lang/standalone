<?php
declare(strict_types=1);

/**
 * Admin V2.90 — Proactive Admin Intelligence & Investigation Plans
 *
 * This layer is deliberately non-executing. It derives permission-filtered
 * signals/evidence from existing Admin state and stores plan/evidence metadata
 * on Admin Agent messages. Governed mutations remain owned by admin-operations.
 */

function admin_agent_v290_can(PDO $pdo,array $admin,string $capability): bool {
    return function_exists('admin_agent_can')?admin_agent_can($pdo,$admin,$capability):true;
}

function admin_agent_v290_plan_intent(string $prompt): bool {
    $p=mb_strtolower(trim($prompt));
    foreach(['plan','investigate','investigation','triage','follow up','follow-up','next steps','what should','resolve','work through','action items'] as $needle){
        if(str_contains($p,$needle))return true;
    }
    return false;
}

function admin_agent_v290_signal_intent(string $prompt): bool {
    if(admin_agent_v290_plan_intent($prompt))return true;
    $p=mb_strtolower(trim($prompt));
    foreach(['attention','risk','problem','issue','health','status','summary','priorit','urgent','what is going on','what\'s going on'] as $needle){
        if(str_contains($p,$needle))return true;
    }
    return false;
}

function admin_agent_v290_proactive_brief(PDO $pdo,array $admin): array {
    if(function_exists('admin_agent_assert_admin'))admin_agent_assert_admin($pdo,$admin);
    $snapshot=function_exists('admin_ui_dashboard_snapshot')?admin_ui_dashboard_snapshot($pdo):[];
    $safe=function_exists('admin_agent_safe_dashboard_snapshot')?admin_agent_safe_dashboard_snapshot($pdo,$admin,$snapshot):$snapshot;
    $signals=[];
    foreach((array)($safe['attention']??[]) as $row){
        $label=mb_substr(trim((string)($row['label']??'')),0,180);
        $url=trim((string)($row['url']??''));
        $count=max(0,(int)($row['count']??0));
        $severity=in_array((string)($row['severity']??''),['danger','warn'],true)?(string)$row['severity']:'warn';
        if($label===''||$count<1||(!str_starts_with($url,'/admin/')&&$url!=='/upgrade.php'))continue;
        $signals[]=[
            'id'=>'signal-'.substr(hash('sha256',$label.'|'.$url),0,16),
            'label'=>$label,
            'count'=>$count,
            'severity'=>$severity,
            'url'=>$url,
            'detail'=>$count.' item'.($count===1?'':'s').' currently require review.',
            '_score'=>($severity==='danger'?200:100)+min($count,50),
        ];
    }
    if(admin_agent_v290_can($pdo,$admin,'admin.actions.view')&&function_exists('admin_ops_ready')&&admin_ops_ready($pdo)){
        try{
            $q=$pdo->query("SELECT status,COUNT(*) count FROM admin_action_records WHERE status IN ('previewed','pending_approval','approved') GROUP BY status");
            foreach($q->fetchAll()?:[] as $row){
                $status=(string)$row['status'];$count=(int)$row['count'];if($count<1)continue;
                $label=$status==='pending_approval'?'Admin actions awaiting approval':($status==='approved'?'Approved Admin actions awaiting execution':'Governed Admin previews awaiting review');
                $signals[]=[
                    'id'=>'signal-action-'.$status,
                    'label'=>$label,
                    'count'=>$count,
                    'severity'=>$status==='pending_approval'?'danger':'warn',
                    'url'=>'/admin/action-center.php',
                    'detail'=>$count.' governed action'.($count===1?'':'s').' in '.str_replace('_',' ',$status).' state.',
                    '_score'=>($status==='pending_approval'?230:130)+min($count,50),
                ];
            }
        }catch(Throwable $e){}
    }
    usort($signals,fn(array $a,array $b)=>(int)$b['_score']<=>(int)$a['_score']);
    foreach($signals as &$signal)unset($signal['_score']);unset($signal);
    return array_slice($signals,0,8);
}

function admin_agent_v290_page_context(PDO $pdo,array $admin,array $input): ?array {
    if(!$input||!function_exists('admin_agent_page_context'))return null;
    $path=trim((string)($input['path']??''));$query=is_array($input['query']??null)?$input['query']:[];
    return $path!==''?admin_agent_page_context($pdo,$admin,$path,$query):null;
}

function admin_agent_v290_evidence(PDO $pdo,array $admin,string $prompt,array $pageContextInput=[]): array {
    $out=[];$seen=[];
    $push=function(string $source,string $title,string $detail,string $url='')use(&$out,&$seen): void {
        $title=mb_substr(trim($title),0,180);$detail=mb_substr(trim($detail),0,360);$url=trim($url);
        if($title==='')return;if($url!==''&&!str_starts_with($url,'/admin/')&&$url!=='/upgrade.php')$url='';
        $key=hash('sha256',$source.'|'.$title.'|'.$url);if(isset($seen[$key]))return;$seen[$key]=true;
        $out[]=['evidence_id'=>'E'.(count($out)+1),'source'=>$source,'title'=>$title,'detail'=>$detail,'url'=>$url];
    };
    $ctx=admin_agent_v290_page_context($pdo,$admin,$pageContextInput);
    if($ctx){
        $path=(string)($ctx['path']??'');$query=is_array($ctx['query']??null)?$ctx['query']:[];
        $url=$path.($query?'?'.http_build_query($query):'');
        $push('current_page',(string)($ctx['label']??'Current Admin page'),(string)($ctx['meta']??'Server-validated Admin page context.'),$url);
    }
    if(function_exists('admin_agent_search')){
        try{
            foreach(array_slice(admin_agent_search($pdo,$admin,$prompt),0,4) as $row){
                $push('admin_search',(string)($row['title']??$row['identifier']??'Admin result'),(string)($row['subtitle']??$row['type']??''),(string)($row['url']??''));
            }
        }catch(Throwable $e){}
    }
    if(admin_agent_v290_signal_intent($prompt)){
        foreach(array_slice(admin_agent_v290_proactive_brief($pdo,$admin),0,4) as $row){
            $push('proactive_signal',(string)$row['label'],(string)$row['detail'],(string)$row['url']);
        }
    }
    return array_slice($out,0,8);
}

function admin_agent_v290_message_owner(PDO $pdo,array $admin,string $threadPublic,int $messageId): ?array {
    if(!function_exists('admin_agent_thread_access'))return null;
    $thread=admin_agent_thread_access($pdo,$admin,$threadPublic);if(!$thread)return null;
    $q=$pdo->prepare("SELECT id,public_id,sender_type,parent_message_id,body FROM conversation_messages WHERE id=? AND conversation_id=? AND deleted_at IS NULL LIMIT 1");
    $q->execute([$messageId,(int)$thread['id']]);$row=$q->fetch();
    return $row?['thread'=>$thread,'message'=>$row]:null;
}

function admin_agent_v290_existing_meta(PDO $pdo,int $messageId): array {
    $q=$pdo->prepare("SELECT attachment_type,object_public_id,metadata_json FROM conversation_message_attachments WHERE message_id=? AND attachment_type IN ('admin_evidence','admin_plan') ORDER BY id");
    $q->execute([$messageId]);$evidence=[];$plans=[];
    foreach($q->fetchAll()?:[] as $row){
        $meta=json_decode((string)($row['metadata_json']??''),true);if(!is_array($meta))continue;
        if($row['attachment_type']==='admin_evidence')$evidence[]=$meta;
        elseif($row['attachment_type']==='admin_plan'){$meta['public_id']=(string)$row['object_public_id'];$plans[]=$meta;}
    }
    return ['admin_evidence'=>$evidence,'admin_plans'=>$plans];
}

function admin_agent_v290_build_plan(string $threadPublic,string $prompt,array $evidence): ?array {
    if(!admin_agent_v290_plan_intent($prompt))return null;
    $steps=[];$seen=[];
    foreach($evidence as $row){
        $url=trim((string)($row['url']??''));if($url===''||isset($seen[$url]))continue;$seen[$url]=true;
        $title=mb_substr(trim((string)($row['title']??'Admin evidence')),0,160);
        $steps[]=['id'=>'step-'.(count($steps)+1),'title'=>'Review '.$title,'detail'=>mb_substr((string)($row['detail']??''),0,320),'url'=>$url,'status'=>'pending'];
        if(count($steps)>=5)break;
    }
    if(!$steps)$steps[]=['id'=>'step-1','title'=>'Review the current Admin evidence','detail'=>'Inspect the authorized Admin records related to this request before deciding on a change.','url'=>'/admin/assistant.php?thread='.rawurlencode($threadPublic),'status'=>'pending'];
    $steps[]=['id'=>'step-'.(count($steps)+1),'title'=>'Return to Admin Agent with findings','detail'=>'Summarize what you verified. Any governed mutation still requires its normal preview, permissions, approvals and Action Center execution.','url'=>'/admin/assistant.php?thread='.rawurlencode($threadPublic),'status'=>'pending'];
    return [
        'title'=>'Admin investigation plan',
        'summary'=>mb_substr('Track the evidence-backed follow-up for: '.preg_replace('/\s+/u',' ',trim($prompt)),0,420),
        'status'=>'active',
        'steps'=>$steps,
    ];
}

function admin_agent_v290_enrich(PDO $pdo,array $admin,string $threadPublic,int $assistantMessageId,string $prompt,array $pageContextInput=[]): array {
    $owned=admin_agent_v290_message_owner($pdo,$admin,$threadPublic,$assistantMessageId);
    if(!$owned||($owned['message']['sender_type']??'')!=='agent')throw new RuntimeException('Admin Agent response not found.');
    $existing=admin_agent_v290_existing_meta($pdo,$assistantMessageId);
    if($existing['admin_evidence']||$existing['admin_plans'])return $existing+['proactive_brief'=>admin_agent_v290_proactive_brief($pdo,$admin)];

    $evidence=admin_agent_v290_evidence($pdo,$admin,$prompt,$pageContextInput);
    foreach($evidence as $row){
        $object='evidence-'.substr(hash('sha256',$assistantMessageId.'|'.$row['evidence_id'].'|'.$row['title']),0,32);
        $pdo->prepare("INSERT INTO conversation_message_attachments(message_id,attachment_type,object_public_id,metadata_json) VALUES(?,'admin_evidence',?,?)")
            ->execute([$assistantMessageId,$object,json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
    $plans=[];$plan=admin_agent_v290_build_plan($threadPublic,$prompt,$evidence);
    if($plan){
        $public='plan-'.substr(hash('sha256',ulid_like().'|'.$assistantMessageId.'|'.$plan['title']),0,28);$plan['public_id']=$public;
        $pdo->prepare("INSERT INTO conversation_message_attachments(message_id,attachment_type,object_public_id,metadata_json) VALUES(?,'admin_plan',?,?)")
            ->execute([$assistantMessageId,$public,json_encode($plan,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $plans[]=$plan;
    }
    return ['admin_evidence'=>$evidence,'admin_plans'=>$plans,'proactive_brief'=>admin_agent_v290_proactive_brief($pdo,$admin)];
}

function admin_agent_v290_message_meta(PDO $pdo,array $admin,string $threadPublic,array $messageIds): array {
    $thread=function_exists('admin_agent_thread_access')?admin_agent_thread_access($pdo,$admin,$threadPublic):null;if(!$thread)return [];
    $ids=array_values(array_unique(array_filter(array_map('intval',$messageIds),fn(int $id)=>$id>0)));$ids=array_slice($ids,0,100);if(!$ids)return [];
    $marks=implode(',',array_fill(0,count($ids),'?'));$params=$ids;$params[]=(int)$thread['id'];
    $q=$pdo->prepare("SELECT a.message_id,a.attachment_type,a.object_public_id,a.metadata_json FROM conversation_message_attachments a JOIN conversation_messages m ON m.id=a.message_id WHERE a.message_id IN ($marks) AND m.conversation_id=? AND a.attachment_type IN ('admin_evidence','admin_plan') ORDER BY a.id");
    $q->execute($params);$out=[];
    foreach($q->fetchAll()?:[] as $row){
        $mid=(int)$row['message_id'];if(!isset($out[$mid]))$out[$mid]=['admin_evidence'=>[],'admin_plans'=>[]];
        $meta=json_decode((string)($row['metadata_json']??''),true);if(!is_array($meta))continue;
        if($row['attachment_type']==='admin_evidence')$out[$mid]['admin_evidence'][]=$meta;
        else{$meta['public_id']=(string)$row['object_public_id'];$out[$mid]['admin_plans'][]=$meta;}
    }
    return $out;
}

function admin_agent_v290_plan_step(PDO $pdo,array $admin,string $threadPublic,string $planPublic,string $stepId,string $status): array {
    $thread=function_exists('admin_agent_thread_access')?admin_agent_thread_access($pdo,$admin,$threadPublic):null;if(!$thread)throw new RuntimeException('Admin Agent thread not found.');
    $status=strtolower(trim($status));if(!in_array($status,['pending','done'],true))throw new InvalidArgumentException('Plan step status must be pending or done.');
    $q=$pdo->prepare("SELECT a.id,a.metadata_json FROM conversation_message_attachments a JOIN conversation_messages m ON m.id=a.message_id WHERE a.attachment_type='admin_plan' AND a.object_public_id=? AND m.conversation_id=? LIMIT 1");
    $q->execute([$planPublic,(int)$thread['id']]);$row=$q->fetch();if(!$row)throw new RuntimeException('Admin Agent plan not found.');
    $plan=json_decode((string)$row['metadata_json'],true);if(!is_array($plan))throw new RuntimeException('Admin Agent plan is invalid.');$found=false;$steps=(array)($plan['steps']??[]);
    foreach($steps as &$step){
        if((string)($step['id']??'')!==$stepId)continue;$step['status']=$status;$found=true;break;
    }unset($step);
    if(!$found)throw new InvalidArgumentException('Plan step not found.');
    $plan['steps']=$steps;$done=count(array_filter($steps,fn(array $s)=>(string)($s['status']??'pending')==='done'));
    $plan['status']=$steps&&$done===count($steps)?'done':'active';$plan['public_id']=$planPublic;
    $pdo->prepare("UPDATE conversation_message_attachments SET metadata_json=? WHERE id=?")->execute([json_encode($plan,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(int)$row['id']]);
    return $plan;
}
