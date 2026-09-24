<?php
declare(strict_types=1);
require_once __DIR__.'/ai.php';
require_once __DIR__.'/ai-access.php';
require_once __DIR__.'/research-workspace.php';
require_once __DIR__.'/agent-actions.php';

function agent_chat_available(PDO $pdo,array $viewer): bool {
    return user_is_pro($pdo,$viewer)||(($viewer['role']??'')==='admin');
}
function agent_chat_create(PDO $pdo,array $viewer,string $title='New Research'): array {
    if(!conversation_runtime_ready($pdo))throw new RuntimeException('Conversation runtime is unavailable.');
    $public=ulid_like();$title=trim($title);if($title==='')$title='New Research';$title=mb_substr($title,0,190);
    $pdo->beginTransaction();
    try{
        $pdo->prepare("INSERT INTO conversations(public_id,conversation_type,created_by_user_id,title) VALUES(?,'agent',?,?)")->execute([$public,$viewer['id'],$title]);
        $id=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO conversation_members(conversation_id,user_id,member_role) VALUES(?,?,'owner')")->execute([$id,$viewer['id']]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['id'=>$id,'public_id'=>$public,'title'=>$title,'conversation_type'=>'agent'];
}
function agent_chat_access(PDO $pdo,array $viewer,string $publicId): ?array {
    $row=conversation_access($pdo,$viewer,$publicId);if(!$row||($row['conversation_type']??'')!=='agent')return null;
    try{
        if(installer_table_exists($pdo,'research_agents')){
            $q=$pdo->prepare("SELECT ra.team_id FROM research_agents ra WHERE ra.conversation_id=? AND ra.status<>'archived' LIMIT 1");
            $q->execute([(int)$row['id']]);$teamId=(int)($q->fetchColumn()?:0);
            if($teamId>0){
                $m=$pdo->prepare("SELECT role FROM team_members WHERE team_id=? AND user_id=? LIMIT 1");$m->execute([$teamId,(int)$viewer['id']]);$role=(string)($m->fetchColumn()?:'');
                if($role==='')return null;
                $pdo->prepare("INSERT IGNORE INTO conversation_members(conversation_id,user_id,member_role) VALUES(?,?,?)")
                    ->execute([(int)$row['id'],(int)$viewer['id'],in_array($role,['owner','admin'],true)?$role:'member']);
            }
        }
    }catch(Throwable $e){return null;}
    return $row;
}
function agent_chat_list(PDO $pdo,array $viewer,int $limit=30): array {
    $limit=max(1,min(100,$limit));$q=$pdo->prepare("SELECT c.public_id,c.title,c.last_message_at,c.created_at,c.updated_at,
      (SELECT body FROM conversation_messages m WHERE m.conversation_id=c.id AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 1) last_message
      FROM conversations c JOIN conversation_members cm ON cm.conversation_id=c.id AND cm.user_id=?
      WHERE c.conversation_type='agent' ORDER BY COALESCE(c.last_message_at,c.updated_at) DESC,c.id DESC LIMIT ".$limit);
    $q->execute([$viewer['id']]);$rows=$q->fetchAll();$rows=array_values(array_filter($rows,fn($row)=>agent_chat_access($pdo,$viewer,(string)$row['public_id'])!==null));foreach($rows as &$row)$row['last_message']=mb_substr((string)($row['last_message']??''),0,120);unset($row);return $rows;
}
function agent_chat_message_rows(PDO $pdo,array $viewer,string $conversationPublicId,?int $beforeId=null,int $limit=60): ?array {
    $c=agent_chat_access($pdo,$viewer,$conversationPublicId);if(!$c)return null;
    $data=conversation_message_rows($pdo,$viewer,$conversationPublicId,$beforeId,$limit);if(!$data)return null;
    $messageIds=array_map(fn($m)=>(int)$m['id'],$data['messages']);$attachments=[];
    if($messageIds){
        $marks=implode(',',array_fill(0,count($messageIds),'?'));
        $q=$pdo->prepare("SELECT message_id,attachment_type,object_public_id,metadata_json FROM conversation_message_attachments WHERE message_id IN ($marks) ORDER BY id");$q->execute($messageIds);
        foreach($q->fetchAll() as $a){
            $type=(string)$a['attachment_type'];$id=(string)$a['object_public_id'];$item=null;
            if($type==='document'&&function_exists('object_handoff_resolve')){$item=object_handoff_resolve($pdo,$viewer,$type,$id);if($item)$item['available']=true;}
            if(!$item){$meta=json_decode((string)($a['metadata_json']??''),true);$item=['type'=>$type,'public_id'=>$id,'metadata'=>is_array($meta)?$meta:[]];}
            $attachments[(int)$a['message_id']][]=$item;
        }
    }
    foreach($data['messages'] as &$m){$m['attachments']=$m['deleted_at']!==null?[]:($attachments[(int)$m['id']]??[]);$m['role']=($m['sender_type']??'user')==='agent'?'assistant':'user';$m['action_proposals']=($m['role']==='assistant'&&agent_actions_ready($pdo))?agent_action_message_proposals($pdo,$viewer,(int)$m['id']):[];}unset($m);
    if(function_exists('data_response_attribution_map')){$lineage=data_response_attribution_map($pdo,$messageIds);foreach($data['messages'] as &$m)if(isset($lineage[(int)$m['id']]))$m['attribution']=$lineage[(int)$m['id']];unset($m);}
    return $data;
}
function agent_chat_context_options(PDO $pdo,array $viewer): array {
    $out=['annotations'=>[],'research'=>[],'teams'=>[]];
    $q=$pdo->prepare("SELECT a.public_id,a.text_commentary,s.title source_title,s.domain,a.created_at FROM annotations a JOIN sources s ON s.id=a.source_id WHERE a.user_id=? AND a.status='published' ORDER BY a.created_at DESC LIMIT 12");$q->execute([$viewer['id']]);
    foreach($q->fetchAll() as $r)$out['annotations'][]=['type'=>'annotation','public_id'=>$r['public_id'],'label'=>trim((string)$r['text_commentary'])!==''?mb_substr(trim((string)$r['text_commentary']),0,80):($r['source_title']?:$r['domain'])];
    $q=$pdo->prepare("SELECT rp.public_id,rp.title FROM research_projects rp WHERE rp.owner_user_id=? OR EXISTS(SELECT 1 FROM team_members tm WHERE tm.team_id=rp.team_id AND tm.user_id=?) ORDER BY rp.created_at DESC,rp.id DESC LIMIT 12");$q->execute([$viewer['id'],$viewer['id']]);
    foreach($q->fetchAll() as $r)$out['research'][]=['type'=>'research','public_id'=>$r['public_id'],'label'=>$r['title']];
    $q=$pdo->prepare("SELECT t.public_id,t.name FROM teams t JOIN team_members tm ON tm.team_id=t.id AND tm.user_id=? ORDER BY t.name LIMIT 12");$q->execute([$viewer['id']]);
    foreach($q->fetchAll() as $r)$out['teams'][]=['type'=>'team','public_id'=>$r['public_id'],'label'=>$r['name']];
    return $out;
}
function agent_chat_context_item(PDO $pdo,array $viewer,string $type,string $publicId): ?array {
    $type=strtolower(trim($type));$publicId=trim($publicId);if($publicId==='')return null;
    if($type==='annotation'){
        $a=annotation_access($pdo,$publicId,$viewer);if(!$a)return null;
        if((int)$a['user_id']!==(int)$viewer['id']&&function_exists('is_blocked')&&is_blocked($pdo,(int)$viewer['id'],(int)$a['user_id']))return null;
        $q=$pdo->prepare("SELECT a.public_id,a.text_commentary,c.selected_text,COALESCE(at.edited_text,at.raw_text) transcript_text,s.public_id source_public_id,s.title,s.canonical_url,s.domain FROM annotations a JOIN captures c ON c.id=a.capture_id JOIN sources s ON s.id=a.source_id LEFT JOIN annotation_transcripts at ON at.annotation_id=a.id WHERE a.id=?");$q->execute([$a['id']]);$r=$q->fetch();if(!$r)return null;
        $text="[ANNOTATION {$r['public_id']}]\nSource: ".($r['title']?:$r['canonical_url'])."\nCommentary: ".mb_substr((string)$r['text_commentary'],0,2500)."\nCaptured text: ".mb_substr((string)$r['selected_text'],0,4000).(!empty($r['transcript_text'])?"\nTranscript: ".mb_substr((string)$r['transcript_text'],0,5000):'');
        $derived=annotation_intelligence_context_text($pdo,$publicId,$viewer);if($derived!=='')$text.="\n\n".$derived;
        return ['type'=>'annotation','public_id'=>$publicId,'label'=>mb_substr((string)($r['text_commentary']?:($r['title']?:$r['domain'])),0,90),'text'=>$text,'refs'=>[['type'=>'annotation','id'=>$publicId],['type'=>'source','id'=>$r['source_public_id']]]];
    }
    if($type==='bookmark'&&function_exists('research_agent_workspace_bookmark_context')){
        return research_agent_workspace_bookmark_context($pdo,$viewer,$publicId);
    }
    if($type==='document'&&function_exists('research_agent_workspace_document_context')){
        return research_agent_workspace_document_context($pdo,$viewer,$publicId);
    }
    if($type==='sticky'&&function_exists('research_agent_workspace_sticky_context')){
        return research_agent_workspace_sticky_context($pdo,$viewer,$publicId);
    }
    if($type==='upload'&&function_exists('research_agent_workspace_upload_context')){
        return research_agent_workspace_upload_context($pdo,$viewer,$publicId);
    }
    if($type==='recording'&&function_exists('research_agent_workspace_recording_context')){
        return research_agent_workspace_recording_context($pdo,$viewer,$publicId);
    }
    if($type==='source'){
        $s=source_access($pdo,$publicId,$viewer);if(!$s)return null;
        $q=$pdo->prepare("SELECT s.public_id,s.title,s.canonical_url,s.domain,sv.extracted_text FROM sources s LEFT JOIN source_versions sv ON sv.id=s.current_version_id WHERE s.id=?");$q->execute([$s['id']]);$r=$q->fetch();if(!$r)return null;
        return ['type'=>'source','public_id'=>$publicId,'label'=>$r['title']?:$r['domain'],'text'=>"[SOURCE {$r['public_id']}]\n".($r['title']?:$r['canonical_url'])."\n".mb_substr((string)$r['extracted_text'],0,9000),'refs'=>[['type'=>'source','id'=>$publicId]]];
    }
    if($type==='research'){
        $p=project_access($pdo,(int)$viewer['id'],$publicId);if(!$p)return null;
        $ctx=(function_exists('research_retrieval_ready')&&research_retrieval_ready($pdo))?['text'=>'','refs'=>[]]:ai_research_context($pdo,(int)$p['id']);
        $workspace=research_workspace_ready($pdo)?research_workspace_context($pdo,(int)$p['id']):null;$workspaceRefs=[];
        if($workspace){$snap=$workspace['snapshot']??[];foreach((array)($snap['claims']??[]) as $x)if(!empty($x['public_id']))$workspaceRefs[]=['type'=>'claim','id'=>$x['public_id']];foreach((array)($snap['entities']??[]) as $x)if(!empty($x['public_id']))$workspaceRefs[]=['type'=>'entity','id'=>$x['public_id']];foreach((array)($snap['source_risks']??[]) as $x)if(!empty($x['source_public_id']))$workspaceRefs[]=['type'=>'source','id'=>$x['source_public_id']];foreach((array)($snap['annotation_links']??[]) as $x){if(!empty($x['source_annotation_id']))$workspaceRefs[]=['type'=>'annotation','id'=>$x['source_annotation_id']];if(!empty($x['target_annotation_id']))$workspaceRefs[]=['type'=>'annotation','id'=>$x['target_annotation_id']];}}
        $text="[RESEARCH PROJECT {$p['public_id']}]\nTitle: {$p['title']}\n".$ctx['text'];if($workspace)$text.="\n\n".$workspace['text'];$refs=array_merge([['type'=>'research_project','id'=>$publicId]],$ctx['refs'],$workspaceRefs);
        if((!function_exists('research_retrieval_ready')||!research_retrieval_ready($pdo))&&function_exists('research_agent_workspace_project_context')){$desktopCtx=research_agent_workspace_project_context($pdo,$viewer,$publicId,10);if(!empty($desktopCtx['text']))$text.="\n\n".$desktopCtx['text'];$refs=array_merge($refs,(array)($desktopCtx['refs']??[]));}
        if(function_exists('cross_research_ready')&&cross_research_ready($pdo)){$cross=cross_research_context($pdo,$viewer,$publicId,10);if(!empty($cross['text']))$text.="\n\n".$cross['text'];$refs=array_merge($refs,(array)($cross['refs']??[]));}
        if(function_exists('research_reviews_ready')&&research_reviews_ready($pdo)){$reviews=research_review_context($pdo,$viewer,$publicId,10);if(!empty($reviews['text']))$text.="\n\n".$reviews['text'];$refs=array_merge($refs,(array)($reviews['refs']??[]));}
        if(function_exists('change_impact_ready')&&change_impact_ready($pdo)){$impact=change_impact_context($pdo,$viewer,$publicId,8);if(!empty($impact['text']))$text.="\n\n".$impact['text'];$refs=array_merge($refs,(array)($impact['refs']??[]));}
        if(function_exists('research_outcomes_ready')&&research_outcomes_ready($pdo)){$outcomeCtx=research_outcome_context($pdo,$viewer,$publicId,15);if(!empty($outcomeCtx['text']))$text.="\n\n".$outcomeCtx['text'];$refs=array_merge($refs,(array)($outcomeCtx['refs']??[]));}
        if(function_exists('research_network_ready')&&research_network_ready($pdo)){$networkCtx=research_network_project_context($pdo,$viewer,$publicId,12);if(!empty($networkCtx['text']))$text.="\n\n".$networkCtx['text'];$refs=array_merge($refs,(array)($networkCtx['refs']??[]));}
        if(function_exists('provenance_ready')&&provenance_ready($pdo)){$provCtx=provenance_project_context($pdo,$viewer,$publicId);if(!empty($provCtx['text']))$text.="\n\n".$provCtx['text'];$refs=array_merge($refs,(array)($provCtx['refs']??[]));}
        if(function_exists('research_verification_ready')&&research_verification_ready($pdo)){$verifyCtx=research_verification_context($pdo,$viewer,$publicId,12);if(!empty($verifyCtx['text']))$text.="\n\n".$verifyCtx['text'];$refs=array_merge($refs,(array)($verifyCtx['refs']??[]));}
        if(function_exists('research_evidence_packs_ready')&&research_evidence_packs_ready($pdo)){$packCtx=research_evidence_pack_project_context($pdo,$viewer,$publicId,5);if(!empty($packCtx['text']))$text.="\n\n".$packCtx['text'];$refs=array_merge($refs,(array)($packCtx['refs']??[]));}
        if(function_exists('research_workflow_state')){$workflowCtx=research_workflow_context($pdo,$viewer,$publicId);if(!empty($workflowCtx['text']))$text.="\n\n".$workflowCtx['text'];$refs=array_merge($refs,(array)($workflowCtx['refs']??[]));}
        $seen=[];$refs=array_values(array_filter($refs,function($r)use(&$seen){$k=($r['type']??'').':'.($r['id']??'');if($k===':'||isset($seen[$k]))return false;$seen[$k]=true;return true;}));
        return ['type'=>'research','public_id'=>$publicId,'label'=>$p['title'],'text'=>mb_substr($text,0,30000),'refs'=>$refs];
    }
    if($type==='team'){
        $team=conversation_team_by_public($pdo,$viewer,$publicId);if(!$team)return null;$c=conversation_team_ensure($pdo,$team,$viewer);
        $q=$pdo->prepare("SELECT m.body,m.created_at,u.display_name FROM conversation_messages m LEFT JOIN users u ON u.id=m.user_id WHERE m.conversation_id=? AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 20");$q->execute([$c['id']]);$rows=array_reverse($q->fetchAll());$parts=["[TEAM {$team['public_id']}]","Team: ".$team['name']];foreach($rows as $r)$parts[]=(string)($r['display_name']?:'Member').': '.mb_substr((string)$r['body'],0,1200);
        return ['type'=>'team','public_id'=>$publicId,'label'=>$team['name'],'text'=>mb_substr(implode("\n",$parts),0,12000),'refs'=>[['type'=>'team','id'=>$publicId]]];
    }
    return null;
}
function agent_chat_context_normalize(PDO $pdo,array $viewer,array $items): array {
    $out=[];$seen=[];foreach(array_slice($items,0,6) as $raw){if(!is_array($raw))continue;$type=(string)($raw['type']??'');$id=(string)($raw['public_id']??$raw['id']??'');$key=$type.':'.$id;if(isset($seen[$key]))continue;$item=agent_chat_context_item($pdo,$viewer,$type,$id);if(!$item)throw new InvalidArgumentException('One or more context items are unavailable.');$seen[$key]=true;$out[]=$item;}return $out;
}
function agent_chat_history_text(PDO $pdo,int $conversationId,int $limit=16): string {
    $q=$pdo->prepare("SELECT sender_type,body FROM conversation_messages WHERE conversation_id=? AND deleted_at IS NULL ORDER BY id DESC LIMIT ".max(1,min(30,$limit)));$q->execute([$conversationId]);$rows=array_reverse($q->fetchAll());$parts=[];foreach($rows as $r)$parts[]=(($r['sender_type']??'user')==='agent'?'Assistant':'User').': '.mb_substr((string)$r['body'],0,5000);return implode("\n\n",$parts);
}
function agent_chat_insert_agent_message(PDO $pdo,array $conversation,string $body,int $parentMessageId): array {
    $public=ulid_like();$pdo->prepare("INSERT INTO conversation_messages(public_id,conversation_id,user_id,sender_type,parent_message_id,body) VALUES(?,?,NULL,'agent',?,?)")->execute([$public,$conversation['id'],$parentMessageId,$body]);$id=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE conversations SET last_message_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$conversation['id']]);$pdo->prepare("INSERT INTO conversation_events(conversation_id,event_type,message_id,payload_json) VALUES(?,'agent_message_created',?,NULL)")->execute([$conversation['id'],$id]);
    return ['id'=>$id,'public_id'=>$public,'body'=>$body,'sender_type'=>'agent','role'=>'assistant'];
}
function agent_chat_send(PDO $pdo,array $config,array $viewer,?string $conversationPublicId,string $prompt,array $contextItems=[],?string $clientMessageId=null): array {
    if(!agent_chat_available($pdo,$viewer))throw new RuntimeException('Agent Chat is available to Pro and administrator accounts.');
    $prompt=trim($prompt);if($prompt===''||mb_strlen($prompt)>12000)throw new InvalidArgumentException('Message must be between 1 and 12000 characters.');
    $conversation=$conversationPublicId?agent_chat_access($pdo,$viewer,$conversationPublicId):null;if($conversationPublicId&& !$conversation)throw new RuntimeException('Agent conversation not found.');
    if(!$conversation)$conversation=agent_chat_create($pdo,$viewer,mb_substr(preg_replace('/\s+/u',' ',$prompt),0,80));
    $context=agent_chat_context_normalize($pdo,$viewer,$contextItems);
    $retrievalContext=null;
    if(function_exists('research_agent_by_conversation')){
        try{
            $researchAgent=research_agent_by_conversation($pdo,$viewer,(string)$conversation['public_id']);
            if($researchAgent){
                $hasProject=false;foreach($context as $item)if(in_array((string)($item['type']??''),['research','research_project'],true)&&($item['public_id']??'')===$researchAgent['project_public_id']){$hasProject=true;break;}
                if(!$hasProject){
                    $projectContext=agent_chat_context_item($pdo,$viewer,'research',(string)$researchAgent['project_public_id']);
                    if($projectContext)$context[]=$projectContext;
                }
                if(function_exists('research_retrieval_context')&&research_retrieval_ready($pdo)){
                    try{$retrievalContext=research_retrieval_context($pdo,$config,$viewer,(string)$researchAgent['project_public_id'],$prompt,[],10);}catch(Throwable $ignored){}
                }
                if(function_exists('research_agent_attach_context'))research_agent_attach_context($pdo,$viewer,$researchAgent,$context);
            }
        }catch(Throwable $e){}
    }
    $userMessage=conversation_message_create($pdo,$viewer,$conversation['public_id'],$prompt,null,$clientMessageId);
    $userMessageId=(int)$userMessage['id'];
    if(!$userMessage['created']){
        $q=$pdo->prepare("SELECT id,public_id,body FROM conversation_messages WHERE conversation_id=? AND parent_message_id=? AND sender_type='agent' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");$q->execute([$conversation['id'],$userMessageId]);if($existing=$q->fetch()){$assistant=['id'=>(int)$existing['id'],'public_id'=>$existing['public_id'],'body'=>$existing['body'],'sender_type'=>'agent','role'=>'assistant','action_proposals'=>agent_actions_ready($pdo)?agent_action_message_proposals($pdo,$viewer,(int)$existing['id']):[]];if(function_exists('data_response_attribution_map')){$map=data_response_attribution_map($pdo,[(int)$existing['id']]);$assistant['attribution']=$map[(int)$existing['id']]??null;}return ['conversation'=>['public_id'=>$conversation['public_id'],'title'=>$conversation['title']],'user_message'=>$userMessage,'assistant_message'=>$assistant,'deduplicated'=>true];}
    }
    if($userMessage['created'])foreach($context as $item)$pdo->prepare('INSERT INTO conversation_message_attachments(message_id,attachment_type,object_public_id,metadata_json) VALUES(?,?,?,?)')->execute([$userMessageId,$item['type'],$item['public_id'],json_encode(['label'=>$item['label']],JSON_UNESCAPED_SLASHES)]);
    if(function_exists('object_handoff_message_attachments')){
        $attachmentMap=object_handoff_message_attachments($pdo,$viewer,[$userMessageId]);
        $userMessage['attachments']=array_values(array_filter((array)($attachmentMap[$userMessageId]??[]),fn($a)=>($a['available']??false)===true));
    }
    $isAdmin=($viewer['role']??'')==='admin';$quota=rate_limit_consume($pdo,$isAdmin?'agent-chat-admin':'agent-chat-pro','user:'.$viewer['id'],$isAdmin?300:60,3600);if(!$quota['allowed'])throw new RuntimeException('Agent Chat request limit reached. Try again in about '.max(1,(int)ceil($quota['retry_after']/60)).' minute(s).');
    $model=$isAdmin?ai_setting_model_id($pdo,'admin',false):ai_setting_model_id($pdo,'pro',true);if(!$model)$model=ai_setting_model_id($pdo,'research',true);if(!$model)throw new RuntimeException('No Agent Chat model is configured.');ai_interactive_model_record($pdo,$viewer,$model);
    $history=agent_chat_history_text($pdo,(int)$conversation['id'],16);$contextText=implode("\n\n",array_map(fn($x)=>$x['text'],$context));$accountContext=function_exists('account_membership_agent_context')?account_membership_agent_context($pdo,$viewer):'';if($accountContext!=='')$contextText.=($contextText!==''?"\n\n":'').$accountContext;$billingContext=function_exists('billing_operations_agent_context')?billing_operations_agent_context($pdo,$viewer):'';if($billingContext!=='')$contextText.=($contextText!==''?"\n\n":'').$billingContext;$overageContext=function_exists('ai_overage_agent_context')?ai_overage_agent_context($pdo,$viewer):'';if($overageContext!=='')$contextText.=($contextText!==''?"\n\n":'').$overageContext;$commercialContext=function_exists('commercial_promotions_agent_context')?commercial_promotions_agent_context($pdo,$viewer):'';if($commercialContext!=='')$contextText.=($contextText!==''?"\n\n":'').$commercialContext;$taxInvoiceContext=function_exists('commercial_billing_agent_context')?commercial_billing_agent_context($pdo,$viewer):'';if($taxInvoiceContext!=='')$contextText.=($contextText!==''?"\n\n":'').$taxInvoiceContext;$opsContext=function_exists('admin_ops_agent_context')?admin_ops_agent_context($pdo,$viewer):'';if($opsContext!=='')$contextText.=($contextText!==''?"\n\n":'').$opsContext;$supportContext=function_exists('admin_support_agent_context')?admin_support_agent_context($pdo,$viewer):'';if($supportContext!=='')$contextText.=($contextText!==''?"\n\n":'').$supportContext;$financeContext=function_exists('admin_finance_agent_context')?admin_finance_agent_context($pdo,$viewer):'';if($financeContext!=='')$contextText.=($contextText!==''?"\n\n":'').$financeContext;$customerSuccessContext=function_exists('admin_customer_success_agent_context')?admin_customer_success_agent_context($pdo,$viewer):'';if($customerSuccessContext!=='')$contextText.=($contextText!==''?"\n\n":'').$customerSuccessContext;$securityContext=function_exists('admin_security_agent_context')?admin_security_agent_context($pdo,$viewer):'';if($securityContext!=='')$contextText.=($contextText!==''?"\n\n":'').$securityContext;$platformContext=function_exists('admin_platform_agent_context')?admin_platform_agent_context($pdo,$config,$viewer):'';if($platformContext!=='')$contextText.=($contextText!==''?"\n\n":'').$platformContext;$refs=[];foreach($context as $item)foreach($item['refs'] as $ref)$refs[]=$ref;
    if($retrievalContext&&!empty($retrievalContext['text'])){$contextText.=($contextText!==''?"\n\n":'').$retrievalContext['text'];foreach((array)($retrievalContext['refs']??[]) as $ref)$refs[]=$ref;}
    $system='You are Annotated Agent Chat, a provenance-first research and collaboration assistant. Distinguish captured/source evidence from inference. Never invent Annotated IDs, quotes, people, team activity, or research findings. When structured Annotated context is supplied, cite its IDs in brackets. For unified Research retrieval evidence, preserve the supplied locator exactly when present, for example [DOCUMENT id · Section name], [UPLOAD id · Page 6], or [RECORDING id · 08:42]. If evidence is missing, say what is missing. Be concise, useful, and action-oriented. Do not claim an action was executed unless the application explicitly confirms it. Commercial account membership is separate from Research Teams. You may explain seat usage and account membership from supplied commercial-account context, but you cannot add, remove, invite, or transfer account members; direct owners/admins to /account-members.php for explicit governed membership changes. Admin billing analytics context is read-only: never claim you changed billing, dunning, trial dates, payment state, refunds, package state, account access, or cancellation status; explicit billing operations remain in Admin billing surfaces. AI overage billing context is also read-only: never claim you changed overage opt-in, caps, rates, usage charges, Stripe reporting, or reconciliation. Promotions and credits context is read-only: never claim you created, edited, synchronized, redeemed, credited, debited, reversed, or otherwise changed a promotion or customer credit balance. Tax and invoice context is read-only: never claim you changed automatic-tax policy, billing profiles, tax exemption, invoice content, subscription tax settings, invoice evidence, or reconciliation. Admin V2.0 operations context is read-only: never claim you resolved or snoozed alerts, changed alert ownership, executed a governed action, or changed operator capabilities. Admin V2.20 support context is read-only: never claim you created, assigned, prioritized, escalated, resolved, reopened, linked, merged, or communicated on a support case. Admin V2.30 finance context is read-only: never claim you ran reconciliation, resolved finance exceptions, closed a reporting period, exported finance data, issued credits or refunds, or changed any financial ledger. Admin V2.40 Customer Success context is read-only: never claim you assigned a Success owner, changed a health snapshot, created or completed a follow-up, changed a success plan or milestone, contacted a customer, or changed account/billing state. Admin V2.50 Security & Compliance context is read-only: never claim you changed roles or permissions, opened or resolved a security case, changed a privacy workflow, altered audit evidence, or created an audit export. Admin V2.60 Platform Governance context is read-only: never claim you changed feature rollouts, module/integration governance, server configuration, provider secrets, migrations, release packages, backups, workers or deployment state.';
    $researchProjects=array_filter(agent_action_project_map($pdo,$viewer,$context),fn($p)=>project_can_write($p));
    if($researchProjects&&agent_actions_ready($pdo)){
        $ids=implode(', ',array_keys($researchProjects));
        $system.="\n\nYou may PROPOSE bounded Research writes, but you cannot execute them. If a write would materially help answer the user or the user asks you to create/save/attach/link Research work, append exactly one machine-readable block at the END of your normal response using this marker followed by a JSON array: <<ANNOTATED_ACTIONS>>[{\"capability\":\"research.create_task\",\"project_id\":\"...\",\"arguments\":{...}}]. Never put this marker inside prose. Only use these attached Research project IDs: ".$ids.". Only propose capabilities from this registry:\n".agent_action_capability_prompt()."\nEvery proposed write requires explicit user confirmation in the UI. Never say it was saved, created, attached, linked, or executed until the application confirms execution. Read/analyze work does not require a proposal.";
    }
    $aiPrompt="Conversation history:\n".$history.($contextText!==''?"\n\nATTACHED ANNOTATED CONTEXT:\n".$contextText:'')."\n\nRespond to the latest user message.";
    $run=ai_run($pdo,$config,$viewer,$isAdmin?'admin':'pro','agent_chat',$model,$system,$aiPrompt,array_merge($refs,[['type'=>'conversation','id'=>$conversation['public_id']]]),'agent_conversation',$conversation['public_id']);
    $parsed=agent_action_extract((string)$run['text']);
    $assistant=agent_chat_insert_agent_message($pdo,$conversation,(string)$parsed['body'],$userMessageId);
    if(function_exists('data_response_try_bind_message'))data_response_try_bind_message($pdo,(string)$run['public_id'],(int)$assistant['id']);
    if(function_exists('data_response_attribution_map')){$map=data_response_attribution_map($pdo,[(int)$assistant['id']]);$assistant['attribution']=$map[(int)$assistant['id']]??null;}
    $proposals=agent_actions_ready($pdo)?agent_action_create_proposals($pdo,$viewer,$conversation,(int)$assistant['id'],$context,(array)$parsed['actions'],$refs):[];
    $assistant['action_proposals']=$proposals;
    return ['conversation'=>['public_id'=>$conversation['public_id'],'title'=>$conversation['title']],'user_message'=>$userMessage,'assistant_message'=>$assistant,'deduplicated'=>false];
}
