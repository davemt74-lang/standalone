<?php
declare(strict_types=1);

/**
 * Phase 31 — Unified Object Handoff & Continuity.
 *
 * Handoffs carry authoritative Annotated object references. They never grant
 * access and never become a second copy of the underlying object.
 */
function object_handoff_types(): array {
    return ['annotation'=>'Annotation','bookmark'=>'Bookmark','document'=>'Research document','upload'=>'Research file','recording'=>'Recording','report'=>'Research Report Run'];
}

function object_handoff_resolve(PDO $pdo,array $viewer,string $type,string $publicId): ?array {
    $type=strtolower(trim($type));$publicId=trim($publicId);
    if($publicId==='')return null;
    if($type==='document'&&function_exists('research_agent_workspace_object')){
        $row=research_agent_workspace_object($pdo,$viewer,$publicId,false);
        if(!$row||($row['object_type']??'')!=='document')return null;
        $preview=trim((string)($row['document_summary']??''));if($preview==='')$preview=mb_substr(trim((string)($row['document_plain_text']??'')),0,360);
        $conversation=trim((string)($row['conversation_public_id']??''));
        return [
          'type'=>'document','public_id'=>(string)$row['public_id'],'label'=>'Research document',
          'title'=>(string)$row['title'],'preview'=>$preview,
          'url'=>$conversation!==''?'/home.php?agent='.rawurlencode($conversation).'&doc='.rawurlencode((string)$row['public_id']):'/research-project.php?id='.rawurlencode((string)$row['project_public_id']),
          'document_type'=>(string)($row['document_type']??'document'),'revision_number'=>(int)($row['revision_number']??1),
          'created_by_agent'=>(bool)($row['created_by_agent']??false),
          'author'=>['username'=>(string)$row['creator_username'],'name'=>(string)($row['creator_name']?:$row['creator_username'])],
          'visibility'=>!empty($row['team_public_id'])?'team':'private',
          'team_public_id'=>$row['team_public_id']??null,'team_name'=>$row['team_name']??null,
          'project_public_id'=>(string)$row['project_public_id'],'research_agent_public_id'=>$row['research_agent_public_id']??null,
          'conversation_public_id'=>$conversation,'created_at'=>$row['created_at']??null,'updated_at'=>$row['updated_at']??null,
        ];
    }
    if(in_array($type,['upload','recording'],true)&&function_exists('research_agent_workspace_object')){
        $row=research_agent_workspace_object($pdo,$viewer,$publicId,false);
        if(!$row||($row['object_type']??'')!==$type)return null;
        $conversation=trim((string)($row['conversation_public_id']??''));
        if($type==='upload'){
            $preview=trim((string)($row['upload_extracted_text']??''));if($preview==='')$preview=(string)($row['upload_original_name']??$row['title']);
            return [
              'type'=>'upload','public_id'=>(string)$row['public_id'],'label'=>'Research file','title'=>(string)$row['title'],
              'preview'=>mb_substr($preview,0,360),'url'=>'/research-workspace-file.php?id='.rawurlencode((string)$row['public_id']),
              'mime_type'=>(string)($row['upload_mime_type']??''),'file_size'=>(int)($row['upload_file_size']??0),'processing_status'=>(string)($row['upload_processing_status']??'queued'),
              'author'=>['username'=>(string)$row['creator_username'],'name'=>(string)($row['creator_name']?:$row['creator_username'])],
              'visibility'=>!empty($row['team_public_id'])?'team':'private','team_public_id'=>$row['team_public_id']??null,'team_name'=>$row['team_name']??null,
              'project_public_id'=>(string)$row['project_public_id'],'research_agent_public_id'=>$row['research_agent_public_id']??null,'conversation_public_id'=>$conversation,
              'created_at'=>$row['created_at']??null,'updated_at'=>$row['updated_at']??null,
            ];
        }
        $preview=trim((string)($row['transcript_text']??''));if($preview==='')$preview='Transcript '.(string)($row['transcript_status']??'queued');
        return [
          'type'=>'recording','public_id'=>(string)$row['public_id'],'label'=>'Recording','title'=>(string)$row['title'],
          'preview'=>mb_substr($preview,0,360),'url'=>'/research-workspace-file.php?id='.rawurlencode((string)$row['public_id']),
          'mime_type'=>(string)($row['recording_mime_type']??''),'file_size'=>(int)($row['recording_file_size']??0),'duration_seconds'=>(float)($row['recording_duration_seconds']??0),
          'transcript_status'=>(string)($row['transcript_status']??'queued'),
          'author'=>['username'=>(string)$row['creator_username'],'name'=>(string)($row['creator_name']?:$row['creator_username'])],
          'visibility'=>!empty($row['team_public_id'])?'team':'private','team_public_id'=>$row['team_public_id']??null,'team_name'=>$row['team_name']??null,
          'project_public_id'=>(string)$row['project_public_id'],'research_agent_public_id'=>$row['research_agent_public_id']??null,'conversation_public_id'=>$conversation,
          'created_at'=>$row['created_at']??null,'updated_at'=>$row['updated_at']??null,
        ];
    }
    if($type==='bookmark'&&function_exists('research_agent_workspace_object')){
        $row=research_agent_workspace_object($pdo,$viewer,$publicId,false);
        if(!$row||($row['object_type']??'')!=='bookmark')return null;
        $preview=trim((string)($row['description']??''));if($preview==='')$preview=(string)($row['canonical_url']??'');
        return [
          'type'=>'bookmark','public_id'=>(string)$row['public_id'],'label'=>'Bookmark',
          'title'=>(string)$row['title'],'preview'=>mb_substr($preview,0,320),
          'url'=>(string)$row['canonical_url'],
          'source'=>['public_id'=>(string)($row['source_public_id']??''),'title'=>(string)($row['source_title']?:$row['title']),'domain'=>(string)($row['domain']??'')],
          'author'=>['username'=>(string)$row['creator_username'],'name'=>(string)($row['creator_name']?:$row['creator_username'])],
          'visibility'=>!empty($row['team_public_id'])?'team':'private',
          'team_public_id'=>$row['team_public_id']??null,'team_name'=>$row['team_name']??null,
          'project_public_id'=>(string)$row['project_public_id'],'research_agent_public_id'=>$row['research_agent_public_id']??null,
          'created_at'=>$row['created_at']??null,
        ];
    }
    if($type==='report'&&function_exists('research_system_report_access')){
        $row=research_system_report_access($pdo,$viewer,$publicId);if(!$row)return null;
        $q=$pdo->prepare("SELECT t.public_id team_public_id,t.name team_name FROM research_projects rp LEFT JOIN teams t ON t.id=rp.team_id WHERE rp.id=? LIMIT 1");$q->execute([(int)$row['project_id']]);$team=$q->fetch()?:[];
        return [
          'type'=>'report','public_id'=>(string)$row['public_id'],'label'=>'Research Report Run','title'=>(string)$row['title'],
          'preview'=>mb_substr(trim((string)($row['rendered_summary']??strip_tags((string)($row['rendered_html']??'')))),0,360),
          'url'=>'/research-reports.php?agent='.rawurlencode((string)$row['agent_public_id']).'&view=recent&report='.rawurlencode((string)$row['public_id']),
          'project_public_id'=>(string)$row['project_public_id'],'research_agent_public_id'=>(string)$row['agent_public_id'],
          'conversation_public_id'=>'','freshness_state'=>(string)($row['freshness_state']??'current'),
          'team_public_id'=>$team['team_public_id']??null,'team_name'=>$team['team_name']??null,'created_at'=>$row['created_at']??null,'updated_at'=>$row['updated_at']??null,
        ];
    }
    if($type!=='annotation')return null;
    $access=annotation_access($pdo,$publicId,$viewer);if(!$access)return null;
    if((int)$access['user_id']!==(int)$viewer['id']&&function_exists('is_blocked')&&is_blocked($pdo,(int)$viewer['id'],(int)$access['user_id']))return null;
    $q=$pdo->prepare("SELECT a.public_id,a.visibility,a.team_id,a.text_commentary,a.published_at,
      u.username,u.display_name,s.public_id source_public_id,s.title source_title,s.domain,
      c.capture_type,c.selected_text
      FROM annotations a
      JOIN users u ON u.id=a.user_id
      JOIN sources s ON s.id=a.source_id
      JOIN captures c ON c.id=a.capture_id
      WHERE a.id=? LIMIT 1");
    $q->execute([(int)$access['id']]);$row=$q->fetch();if(!$row)return null;
    $preview=trim((string)($row['text_commentary']??''));if($preview==='')$preview=trim((string)($row['selected_text']??''));if($preview==='')$preview=(string)($row['source_title']?:$row['domain']?:'Annotation');
    return [
        'type'=>'annotation','public_id'=>(string)$row['public_id'],'label'=>'Annotation',
        'title'=>mb_substr($preview,0,180),'preview'=>mb_substr($preview,0,320),
        'url'=>'/annotation.php?id='.rawurlencode((string)$row['public_id']),
        'source'=>['public_id'=>(string)$row['source_public_id'],'title'=>(string)($row['source_title']?:$row['domain']?:'Source'),'domain'=>(string)($row['domain']??'')],
        'author'=>['username'=>(string)$row['username'],'name'=>(string)($row['display_name']?:$row['username'])],
        'visibility'=>(string)$row['visibility'],'team_id'=>$row['team_id']!==null?(int)$row['team_id']:null,
        'capture_type'=>(string)$row['capture_type'],'published_at'=>$row['published_at'],
    ];
}

function object_handoff_can_share_to_conversation(PDO $pdo,array $viewer,array $conversation,string $type,string $publicId): bool {
    $object=object_handoff_resolve($pdo,$viewer,$type,$publicId);if(!$object)return false;
    if(($conversation['conversation_type']??'')!=='team'||empty($conversation['team_id']))return false;
    $conversationTeamPublic=trim((string)($conversation['team_public_id']??''));
    if($conversationTeamPublic===''){
        $tq=$pdo->prepare('SELECT public_id FROM teams WHERE id=? LIMIT 1');$tq->execute([(int)$conversation['team_id']]);
        $conversationTeamPublic=trim((string)($tq->fetchColumn()?:''));
    }
    if($object['type']==='annotation'){
        if($object['visibility']==='public')return true;
        if($object['visibility']==='team'&&!empty($object['team_id'])&&(int)$object['team_id']===(int)$conversation['team_id'])return true;
        return false;
    }
    if($object['type']==='bookmark'){
        return !empty($object['team_public_id'])
            &&hash_equals((string)$object['team_public_id'],$conversationTeamPublic);
    }
    if($object['type']==='document'){
        return !empty($object['team_public_id'])
            &&hash_equals((string)$object['team_public_id'],$conversationTeamPublic);
    }
    if($object['type']==='report'){
        return !empty($object['team_public_id'])&&hash_equals((string)$object['team_public_id'],$conversationTeamPublic);
    }
    if(in_array($object['type'],['upload','recording'],true)){
        return !empty($object['team_public_id'])
            &&hash_equals((string)$object['team_public_id'],$conversationTeamPublic);
    }
    return false;
}

function object_handoff_normalize_attachments(PDO $pdo,array $viewer,array $conversation,mixed $attachments): array {
    if(!is_array($attachments))return [];$out=[];$seen=[];
    foreach(array_slice($attachments,0,6) as $item){
        if(!is_array($item))continue;$type=strtolower(trim((string)($item['type']??'')));$id=trim((string)($item['public_id']??''));
        if(!isset(object_handoff_types()[$type])||$id===''||strlen($id)>64)continue;$key=$type.':'.$id;if(isset($seen[$key]))continue;
        if(!object_handoff_can_share_to_conversation($pdo,$viewer,$conversation,$type,$id))throw new RuntimeException('This Annotated item cannot be shared to that Team without changing its visibility.');
        $seen[$key]=true;$out[]=['type'=>$type,'public_id'=>$id];
    }
    return $out;
}

function object_handoff_store_message_attachments(PDO $pdo,int $messageId,array $attachments): void {
    if(!$attachments)return;$q=$pdo->prepare('INSERT INTO conversation_message_attachments(message_id,attachment_type,object_public_id,metadata_json) VALUES(?,?,?,NULL)');
    foreach($attachments as $item)$q->execute([$messageId,$item['type'],$item['public_id']]);
}

function object_handoff_message_attachments(PDO $pdo,array $viewer,array $messageIds): array {
    $messageIds=array_values(array_unique(array_filter(array_map('intval',$messageIds),fn($id)=>$id>0)));if(!$messageIds)return [];
    $marks=implode(',',array_fill(0,count($messageIds),'?'));$q=$pdo->prepare("SELECT message_id,attachment_type,object_public_id FROM conversation_message_attachments WHERE message_id IN ($marks) ORDER BY id");$q->execute($messageIds);
    $out=[];$cache=[];foreach($q->fetchAll() as $row){
        $key=(string)$row['attachment_type'].':'.(string)$row['object_public_id'];
        if(!array_key_exists($key,$cache))$cache[$key]=object_handoff_resolve($pdo,$viewer,(string)$row['attachment_type'],(string)$row['object_public_id']);
        $resolved=$cache[$key];
        $out[(int)$row['message_id']][]=$resolved?:['type'=>(string)$row['attachment_type'],'public_id'=>(string)$row['object_public_id'],'available'=>false,'label'=>'Shared item unavailable'];
        if($resolved)$out[(int)$row['message_id']][array_key_last($out[(int)$row['message_id']])]['available']=true;
    }
    return $out;
}

function object_handoff_agent_prompt(string $type): string {
    if($type==='annotation')return 'Review this Annotation as evidence. Summarize what it actually captures, distinguish the author commentary from source evidence, note any integrity or uncertainty concerns, and suggest the most useful next step. Do not create or change Research without confirmation.';
    if($type==='bookmark')return 'Review this Research bookmark and its captured source context. Explain why it may matter to the Research Agent, identify useful evidence or gaps, and suggest the next step. Do not create or change Research without confirmation.';
    if($type==='document')return 'Review this Research document in the context of its owning Research Agent. Identify useful improvements, unsupported claims, missing evidence, and concrete next steps. Do not edit the document unless the user confirms a governed action.';
    if($type==='upload')return 'Review this uploaded Research file and its extracted content. Identify important evidence, uncertainty, missing context, and useful next steps.';
    if($type==='recording')return 'Review this Research recording and its transcript when available. Summarize the useful evidence, decisions, tasks, open questions, and next steps.';
    if($type==='report')return 'Review this Research Report Run against its underlying project evidence. Explain the strongest conclusions, provenance, uncertainty, what changed, and useful next steps. Treat the Report Run as a preserved processing result; do not turn it into a Research Document unless the user confirms that separate action.';
    return 'Review this Annotated item and explain the most useful next step.';
}
