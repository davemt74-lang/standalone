<?php
declare(strict_types=1);

/**
 * Phase 31 — Unified Object Handoff & Continuity.
 *
 * Handoffs carry authoritative Annotated object references. They never grant
 * access and never become a second copy of the underlying object.
 */
function object_handoff_types(): array {
    return ['annotation'=>'Annotation'];
}

function object_handoff_resolve(PDO $pdo,array $viewer,string $type,string $publicId): ?array {
    $type=strtolower(trim($type));$publicId=trim($publicId);
    if($type!=='annotation'||$publicId==='')return null;
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
    if($object['type']==='annotation'){
        if($object['visibility']==='public')return true;
        if($object['visibility']==='team'&&!empty($object['team_id'])&&(int)$object['team_id']===(int)$conversation['team_id'])return true;
        return false;
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
    return $type==='annotation'
        ?'Review this Annotation as evidence. Summarize what it actually captures, distinguish the author commentary from source evidence, note any integrity or uncertainty concerns, and suggest the most useful next step. Do not create or change Research without confirmation.'
        :'Review this Annotated item and explain the most useful next step.';
}
