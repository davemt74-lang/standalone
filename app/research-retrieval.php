<?php
declare(strict_types=1);

/**
 * Phase 54 — Unified Research Knowledge & Retrieval.
 * Project-scoped, permission-checked retrieval across Research Agent workspace objects and source evidence.
 */

function research_retrieval_ready(PDO $pdo): bool {
    try{
        foreach(['research_retrieval_projects','research_retrieval_documents','research_retrieval_chunks','research_retrieval_jobs','research_retrieval_queries'] as $table)
            if(!installer_table_exists($pdo,$table))return false;
        return true;
    }catch(Throwable $e){return false;}
}

function research_retrieval_normalize(string $text,int $max=2000000): string {
    $text=str_replace(["\r\n","\r"],"\n",$text);
    $text=preg_replace("/[ \t]+/u",' ',$text)??$text;
    $text=preg_replace("/\n{3,}/u","\n\n",$text)??$text;
    return mb_substr(trim($text),0,$max);
}

function research_retrieval_seconds_label(?float $start,?float $end=null): string {
    if($start===null)return 'Transcript';
    $fmt=function(float $s): string {$s=max(0,(int)round($s));return sprintf('%02d:%02d',intdiv($s,60),$s%60);};
    return $end!==null&&$end>$start?$fmt($start).'–'.$fmt($end):$fmt($start);
}

function research_retrieval_chunk_text(string $text,string $locatorType='section',string $locatorPrefix='Section',int $target=1800,int $overlap=180): array {
    $text=research_retrieval_normalize($text);if($text==='')return [];
    $paragraphs=preg_split("/\n\s*\n/u",$text)?:[$text];$chunks=[];$buffer='';$index=1;
    $flush=function()use(&$chunks,&$buffer,&$index,$locatorType,$locatorPrefix,$overlap): void {
        $body=trim($buffer);if($body==='')return;
        $chunks[]=['locator_type'=>$locatorType,'locator_label'=>$locatorPrefix.' '.$index,'heading'=>null,'content'=>$body,'locator'=>['index'=>$index]];
        $tail=mb_substr($body,max(0,mb_strlen($body)-$overlap));$buffer=trim($tail);$index++;
    };
    foreach($paragraphs as $paragraph){
        $paragraph=trim((string)$paragraph);if($paragraph==='')continue;
        if(mb_strlen($paragraph)>$target){
            if($buffer!=='')$flush();
            for($offset=0,$n=mb_strlen($paragraph);$offset<$n;$offset+=max(1,$target-$overlap)){
                $part=trim(mb_substr($paragraph,$offset,$target));if($part==='')continue;
                $chunks[]=['locator_type'=>$locatorType,'locator_label'=>$locatorPrefix.' '.$index,'heading'=>null,'content'=>$part,'locator'=>['index'=>$index]];$index++;
                if($offset+$target>=$n)break;
            }
            $buffer='';continue;
        }
        $candidate=$buffer===''?$paragraph:$buffer."\n\n".$paragraph;
        if(mb_strlen($candidate)>$target&&$buffer!=='')$flush();
        $buffer=$buffer===''?$paragraph:$buffer."\n\n".$paragraph;
    }
    if(trim($buffer)!==''){$body=trim($buffer);$chunks[]=['locator_type'=>$locatorType,'locator_label'=>$locatorPrefix.' '.$index,'heading'=>null,'content'=>$body,'locator'=>['index'=>$index]];}
    return $chunks;
}

function research_retrieval_document_chunks(string $html,string $plain): array {
    $html=trim($html);if($html==='')return research_retrieval_chunk_text($plain,'section','Section');
    $parts=preg_split('/(<h[1-3][^>]*>.*?<\/h[1-3]>)/isu',$html,-1,PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY)?:[];
    $chunks=[];$heading='Document';$buffer='';
    $flush=function()use(&$chunks,&$heading,&$buffer): void {
        $plainText=research_agent_workspace_plain_text($buffer);if($plainText===''){$buffer='';return;}
        foreach(research_retrieval_chunk_text($plainText,'section',$heading,1800,160) as $chunk){$chunk['heading']=$heading;$chunk['locator_label']=$heading;$chunks[]=$chunk;}
        $buffer='';
    };
    foreach($parts as $part){
        if(preg_match('/^<h[1-3][^>]*>(.*?)<\/h[1-3]>$/isu',$part,$m)){
            $flush();$heading=trim(html_entity_decode(strip_tags((string)$m[1]),ENT_QUOTES|ENT_HTML5,'UTF-8'))?:'Section';
        }else $buffer.=$part;
    }
    $flush();return $chunks?:research_retrieval_chunk_text($plain,'section','Section');
}

function research_retrieval_pdf_chunks(string $text): array {
    $pages=preg_split("/\f/u",$text)?:[];
    if(count($pages)<=1)return research_retrieval_chunk_text($text,'page','Page');
    $chunks=[];$page=0;
    foreach($pages as $body){$page++;$body=research_retrieval_normalize((string)$body);if($body==='')continue;
        foreach(research_retrieval_chunk_text($body,'page','Page '.$page.' · part',1800,160) as $chunk){
            $chunk['locator_type']='page';$chunk['locator_label']='Page '.$page; $chunk['locator']=['page'=>$page];$chunks[]=$chunk;
        }
    }
    return $chunks;
}

function research_retrieval_recording_chunks(string $text,?string $segmentsJson): array {
    $segments=json_decode((string)$segmentsJson,true);$chunks=[];
    if(is_array($segments)){
        foreach($segments as $segment){
            if(!is_array($segment))continue;$body=trim((string)($segment['text']??$segment['content']??''));if($body==='')continue;
            $start=isset($segment['start'])?(float)$segment['start']:(isset($segment['start_seconds'])?(float)$segment['start_seconds']:null);
            $end=isset($segment['end'])?(float)$segment['end']:(isset($segment['end_seconds'])?(float)$segment['end_seconds']:null);
            $chunks[]=['locator_type'=>'timestamp','locator_label'=>research_retrieval_seconds_label($start,$end),'heading'=>null,'content'=>$body,'locator'=>['start_seconds'=>$start,'end_seconds'=>$end]];
        }
    }
    return $chunks?:research_retrieval_chunk_text($text,'transcript','Transcript');
}

function research_retrieval_record(string $type,string $publicId,string $title,string $content,?string $folderPublicId,?string $updatedAt,array $metadata=[],string $sourceStatus='ready',array $chunks=[]): array {
    $content=research_retrieval_normalize($content);$title=mb_substr(trim($title)?:ucfirst($type),0,255);
    if(!$chunks)$chunks=research_retrieval_chunk_text($content);
    foreach($chunks as &$chunk){$chunk['content']=research_retrieval_normalize((string)($chunk['content']??''),100000);}$chunks=array_values(array_filter($chunks,fn($x)=>($x['content']??'')!==''));
    unset($chunk);
    $chunkSignature=array_map(fn($chunk)=>[
      'locator_type'=>$chunk['locator_type']??null,'locator_label'=>$chunk['locator_label']??null,
      'locator'=>$chunk['locator']??[],'heading'=>$chunk['heading']??null,'content'=>$chunk['content']??''
    ],$chunks);
    $hash=hash('sha256',$type."\0".$publicId."\0".$title."\0".$content."\0".json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\0".json_encode($chunkSignature,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    return ['object_type'=>$type,'object_public_id'=>$publicId,'title'=>$title,'content'=>$content,'folder_public_id'=>$folderPublicId?:null,'source_updated_at'=>$updatedAt,'metadata'=>$metadata,'source_status'=>$sourceStatus,'content_hash'=>$hash,'chunks'=>$chunks];
}

function research_retrieval_collect_records(PDO $pdo,int $projectId): array {
    $records=[];

    $q=$pdo->prepare("SELECT s.public_id,s.title,s.canonical_url,s.domain,s.last_checked_at,sv.version_number,sv.captured_at,sv.extracted_text
      FROM project_sources ps JOIN sources s ON s.id=ps.source_id LEFT JOIN source_versions sv ON sv.id=s.current_version_id
      WHERE ps.project_id=? ORDER BY ps.created_at,s.id");
    $q->execute([$projectId]);
    foreach($q->fetchAll()?:[] as $row){
        $title=(string)($row['title']?:$row['domain']?:$row['canonical_url']?:'Source');
        $content=(string)($row['extracted_text']??'');
        $records[] = research_retrieval_record('source',(string)$row['public_id'],$title,$content,null,(string)($row['captured_at']?:$row['last_checked_at']?:''),[
          'source_url'=>$row['canonical_url']??null,'domain'=>$row['domain']??null,'version_number'=>(int)($row['version_number']??0)
        ],'ready',research_retrieval_chunk_text($content,'source_section','Source section'));
    }

    $q=$pdo->prepare("SELECT a.public_id,a.text_commentary,a.updated_at,a.status,a.visibility,c.selected_text,c.start_seconds,c.end_seconds,c.capture_type,
      COALESCE(at.edited_text,at.raw_text) transcript_text,s.public_id source_public_id,s.title source_title,s.canonical_url,
      folder.public_id folder_public_id,u.public_id creator_public_id
      FROM project_annotations pa JOIN annotations a ON a.id=pa.annotation_id JOIN captures c ON c.id=a.capture_id
      JOIN sources s ON s.id=a.source_id JOIN users u ON u.id=a.user_id
      LEFT JOIN annotation_transcripts at ON at.annotation_id=a.id
      LEFT JOIN research_workspace_desktop_positions rdp ON rdp.project_id=pa.project_id AND rdp.object_type='annotation' AND rdp.object_public_id=a.public_id
      LEFT JOIN research_workspace_objects folder ON folder.id=rdp.folder_object_id
      WHERE pa.project_id=? AND a.status NOT IN ('removed') AND (rdp.folder_object_id IS NULL OR folder.status='active')
      ORDER BY pa.created_at,a.id");
    $q->execute([$projectId]);
    foreach($q->fetchAll()?:[] as $row){
        $parts=[];if(trim((string)$row['text_commentary'])!=='')$parts[]="Commentary: ".trim((string)$row['text_commentary']);
        if(trim((string)$row['selected_text'])!=='')$parts[]="Captured passage: ".trim((string)$row['selected_text']);
        if(trim((string)$row['transcript_text'])!=='')$parts[]="Transcript: ".trim((string)$row['transcript_text']);
        $content=implode("\n\n",$parts);$start=$row['start_seconds']!==null?(float)$row['start_seconds']:null;$end=$row['end_seconds']!==null?(float)$row['end_seconds']:null;
        $label=$start!==null?research_retrieval_seconds_label($start,$end):'Captured passage';
        $chunks=$content!==''?[['locator_type'=>$start!==null?'timestamp':'annotation','locator_label'=>$label,'heading'=>(string)($row['source_title']?:'Annotation'),'content'=>$content,'locator'=>['source_public_id'=>$row['source_public_id'],'start_seconds'=>$start,'end_seconds'=>$end]]]:[];
        $records[]=research_retrieval_record('annotation',(string)$row['public_id'],trim((string)$row['text_commentary'])?:((string)$row['source_title']?:'Annotation'),$content,$row['folder_public_id']??null,(string)$row['updated_at'],[
          'source_public_id'=>$row['source_public_id'],'source_title'=>$row['source_title'],'source_url'=>$row['canonical_url'],
          'visibility'=>$row['visibility'],'creator_public_id'=>$row['creator_public_id'],'capture_type'=>$row['capture_type']
        ],(string)$row['status'],$chunks);
    }

    $q=$pdo->prepare("SELECT rwo.id,rwo.public_id,rwo.object_type,rwo.title,rwo.updated_at,parent.public_id folder_public_id,u.public_id creator_public_id,
      rwb.canonical_url,rwb.domain,rwb.description,s.public_id source_public_id,s.title source_title,sv.extracted_text bookmark_source_text,
      rwd.content_html,rwd.plain_text,rwd.summary,rwd.revision_number,
      rws.body sticky_body,
      rwu.original_name upload_original_name,rwu.mime_type upload_mime_type,rwu.processing_status upload_status,rwu.extracted_text upload_text,rwu.page_count,
      rwr.original_name recording_original_name,rwr.mime_type recording_mime_type,rwr.duration_seconds,
      rwt.status transcript_status,COALESCE(rwt.edited_text,rwt.raw_text) transcript_text,rwt.segments_json
      FROM research_workspace_objects rwo JOIN users u ON u.id=rwo.created_by_user_id
      LEFT JOIN research_workspace_objects parent ON parent.id=rwo.parent_id AND parent.status='active'
      LEFT JOIN research_workspace_bookmarks rwb ON rwb.object_id=rwo.id
      LEFT JOIN sources s ON s.id=rwb.source_id LEFT JOIN source_versions sv ON sv.id=s.current_version_id
      LEFT JOIN research_workspace_documents rwd ON rwd.object_id=rwo.id
      LEFT JOIN research_workspace_stickies rws ON rws.object_id=rwo.id
      LEFT JOIN research_workspace_uploads rwu ON rwu.object_id=rwo.id
      LEFT JOIN research_workspace_recordings rwr ON rwr.object_id=rwo.id
      LEFT JOIN research_workspace_recording_transcripts rwt ON rwt.object_id=rwo.id
      WHERE rwo.project_id=? AND rwo.status='active' AND rwo.object_type IN ('document','bookmark','sticky','upload','recording')
      ORDER BY rwo.id");
    $q->execute([$projectId]);
    foreach($q->fetchAll()?:[] as $row){
        $type=(string)$row['object_type'];$content='';$chunks=[];$metadata=['creator_public_id'=>$row['creator_public_id']??null];$status='ready';
        if($type==='document'){
            $content=trim((string)($row['summary']??''))."\n\n".(string)($row['plain_text']??'');
            $chunks=research_retrieval_document_chunks((string)($row['content_html']??''),(string)($row['plain_text']??''));
            $metadata['revision_number']=(int)($row['revision_number']??0);
        }elseif($type==='bookmark'){
            $content=trim((string)($row['description']??''))."\n\n".trim((string)($row['bookmark_source_text']??''));
            $chunks=research_retrieval_chunk_text($content,'source_section','Source section');
            $metadata+=['url'=>$row['canonical_url']??null,'domain'=>$row['domain']??null,'source_public_id'=>$row['source_public_id']??null,'source_title'=>$row['source_title']??null];
        }elseif($type==='sticky'){
            $content=(string)($row['sticky_body']??'');$chunks=research_retrieval_chunk_text($content,'sticky','Sticky');
        }elseif($type==='upload'){
            $content=(string)($row['upload_text']??'');$status=(string)($row['upload_status']??'queued');
            $mime=(string)($row['upload_mime_type']??'');$chunks=$mime==='application/pdf'?research_retrieval_pdf_chunks($content):research_retrieval_chunk_text($content,'file_section','File section');
            $metadata+=['original_name'=>$row['upload_original_name']??null,'mime_type'=>$mime,'page_count'=>$row['page_count']!==null?(int)$row['page_count']:null];
        }elseif($type==='recording'){
            $content=(string)($row['transcript_text']??'');$status=(string)($row['transcript_status']??'queued');
            $chunks=research_retrieval_recording_chunks($content,(string)($row['segments_json']??''));
            $metadata+=['original_name'=>$row['recording_original_name']??null,'mime_type'=>$row['recording_mime_type']??null,'duration_seconds'=>$row['duration_seconds']!==null?(float)$row['duration_seconds']:null,'transcript_status'=>$status];
        }
        $records[]=research_retrieval_record($type,(string)$row['public_id'],(string)$row['title'],$content,$row['folder_public_id']??null,(string)$row['updated_at'],$metadata,$status,$chunks);
    }
    if(function_exists('research_system_reports_ready')&&research_system_reports_ready($pdo)){
        $q=$pdo->prepare("SELECT rsr.public_id,rsr.report_type,rsr.title,rsr.rendered_html,rsr.rendered_summary,rsr.freshness_state,rsr.generation_mode,rsr.updated_at,
          rwo.public_id document_public_id,ra.public_id agent_public_id,c.public_id agent_conversation_id,rrp.public_id preset_public_id
          FROM research_system_reports rsr
          JOIN research_agents ra ON ra.id=rsr.research_agent_id JOIN conversations c ON c.id=ra.conversation_id
          LEFT JOIN research_workspace_objects rwo ON rwo.id=rsr.document_object_id
          LEFT JOIN research_report_presets rrp ON rrp.id=rsr.preset_id
          WHERE rsr.project_id=? AND rsr.status='ready' ORDER BY rsr.created_at,rsr.id");
        $q->execute([$projectId]);$reportDocs=[];
        foreach($q->fetchAll()?:[] as $row){
            $plain=trim((string)($row['rendered_summary']??''))."\n\n".trim(strip_tags((string)($row['rendered_html']??'')));
            $metadata=['report_type'=>(string)$row['report_type'],'freshness_state'=>(string)$row['freshness_state'],'generation_mode'=>(string)$row['generation_mode'],
              'agent_public_id'=>(string)$row['agent_public_id'],'agent_conversation_id'=>(string)$row['agent_conversation_id'],'document_public_id'=>$row['document_public_id']??null,'preset_public_id'=>$row['preset_public_id']??null];
            $records[]=research_retrieval_record('report',(string)$row['public_id'],(string)$row['title'],$plain,null,(string)$row['updated_at'],$metadata,'ready',
              research_retrieval_document_chunks((string)($row['rendered_html']??''),$plain));
            if(!empty($row['document_public_id']))$reportDocs[(string)$row['document_public_id']]=['source_report_id'=>(string)$row['public_id'],'system_report_type'=>(string)$row['report_type'],'agent_conversation_id'=>(string)$row['agent_conversation_id']];
        }
        if($reportDocs)foreach($records as &$record)if($record['object_type']==='document'&&isset($reportDocs[$record['object_public_id']]))$record['metadata']=array_merge((array)$record['metadata'],$reportDocs[$record['object_public_id']]);unset($record);
    }

    if(installer_table_exists($pdo,'research_claims')){
        $q=$pdo->prepare("SELECT rc.public_id,rc.statement,rc.claim_type,rc.status,rc.resolution_note,rc.updated_at,
          COUNT(ce.id) evidence_count,
          SUM(ce.relationship='supports') supports_count,
          SUM(ce.relationship='contradicts') contradicts_count,
          SUM(ce.relationship='primary') primary_count
          FROM research_claims rc LEFT JOIN claim_evidence ce ON ce.claim_id=rc.id
          WHERE rc.project_id=? GROUP BY rc.id ORDER BY rc.updated_at DESC");
        $q->execute([$projectId]);
        foreach($q->fetchAll()?:[] as $row){
            $content="Claim type: ".(string)$row['claim_type']."\nStatus: ".(string)$row['status']."\nStatement: ".(string)$row['statement'];
            if(trim((string)($row['resolution_note']??''))!=='')$content.="\nResolution: ".trim((string)$row['resolution_note']);
            $content.="\nEvidence: ".(int)$row['evidence_count']." total; ".(int)$row['supports_count']." supports; ".(int)$row['contradicts_count']." contradicts; ".(int)$row['primary_count']." primary.";
            $records[]=research_retrieval_record('claim',(string)$row['public_id'],mb_substr((string)$row['statement'],0,255),$content,null,(string)$row['updated_at'],[
              'claim_type'=>(string)$row['claim_type'],'claim_status'=>(string)$row['status'],'evidence_count'=>(int)$row['evidence_count']
            ],'ready',research_retrieval_chunk_text($content,'claim','Claim'));
        }
    }

    if(installer_table_exists($pdo,'research_findings')){
        $q=$pdo->prepare("SELECT rf.public_id,rf.title,rf.summary,rf.status,rf.updated_at,
          GROUP_CONCAT(CONCAT(rc.public_id,': ',rc.statement) ORDER BY fc.position SEPARATOR '\n') linked_claims
          FROM research_findings rf
          LEFT JOIN finding_claims fc ON fc.finding_id=rf.id LEFT JOIN research_claims rc ON rc.id=fc.claim_id
          WHERE rf.project_id=? AND rf.status<>'archived' GROUP BY rf.id ORDER BY rf.updated_at DESC");
        $q->execute([$projectId]);
        foreach($q->fetchAll()?:[] as $row){
            $content="Finding status: ".(string)$row['status']."\nSummary: ".(string)$row['summary'];
            if(trim((string)($row['linked_claims']??''))!=='')$content.="\nLinked Claims:\n".(string)$row['linked_claims'];
            $records[]=research_retrieval_record('finding',(string)$row['public_id'],(string)$row['title'],$content,null,(string)$row['updated_at'],['finding_status'=>(string)$row['status']],'ready',research_retrieval_chunk_text($content,'finding','Finding'));
        }
    }

    if(installer_table_exists($pdo,'research_entities')){
        $q=$pdo->prepare("SELECT re.public_id,re.entity_type,re.canonical_name,re.description,re.status,re.updated_at,
          COUNT(DISTINCT rem.id) mention_count,COUNT(DISTINCT rr.id) relation_count
          FROM research_entities re
          LEFT JOIN research_entity_mentions rem ON rem.entity_id=re.id
          LEFT JOIN research_entity_relations rr ON rr.source_entity_id=re.id OR rr.target_entity_id=re.id
          WHERE re.project_id=? AND re.status<>'archived'
          GROUP BY re.id ORDER BY re.updated_at DESC");
        $q->execute([$projectId]);
        foreach($q->fetchAll()?:[] as $row){
            $content="Entity type: ".(string)$row['entity_type']."\nStatus: ".(string)$row['status']."\nName: ".(string)$row['canonical_name'];
            if(trim((string)($row['description']??''))!=='')$content.="\nDescription: ".trim((string)$row['description']);
            $content.="\nMentions: ".(int)$row['mention_count']."; relationships: ".(int)$row['relation_count'].".";
            $records[]=research_retrieval_record('entity',(string)$row['public_id'],(string)$row['canonical_name'],$content,null,(string)$row['updated_at'],[
              'entity_type'=>(string)$row['entity_type'],'entity_status'=>(string)$row['status'],'mention_count'=>(int)$row['mention_count'],'relation_count'=>(int)$row['relation_count']
            ],'ready',research_retrieval_chunk_text($content,'entity','Entity'));
        }
    }

    if(installer_table_exists($pdo,'claim_relations')){
        $q=$pdo->prepare("SELECT cr.public_id,cr.relation_type,cr.note,cr.created_at,rp.public_id project_public_id,
          sc.public_id source_public_id,sc.statement source_statement,tc.public_id target_public_id,tc.statement target_statement
          FROM claim_relations cr JOIN research_claims sc ON sc.id=cr.source_claim_id JOIN research_claims tc ON tc.id=cr.target_claim_id
          JOIN research_projects rp ON rp.id=cr.project_id WHERE cr.project_id=? ORDER BY cr.created_at DESC");
        $q->execute([$projectId]);
        foreach($q->fetchAll()?:[] as $row){
            $title=mb_substr((string)$row['source_statement'].' '.str_replace('_',' ',(string)$row['relation_type']).' '.(string)$row['target_statement'],0,255);
            $content="[CLAIM ".(string)$row['source_public_id']."] ".(string)$row['source_statement']."\nRelationship: ".(string)$row['relation_type']."\n[CLAIM ".(string)$row['target_public_id']."] ".(string)$row['target_statement'];
            if(trim((string)($row['note']??''))!=='')$content.="\nNote: ".trim((string)$row['note']);
            $records[]=research_retrieval_record('claim_relation',(string)$row['public_id'],$title,$content,null,(string)$row['created_at'],[
              'relation_type'=>(string)$row['relation_type'],'source_claim_id'=>(string)$row['source_public_id'],'target_claim_id'=>(string)$row['target_public_id'],'project_public_id'=>(string)$row['project_public_id']
            ],'ready',research_retrieval_chunk_text($content,'relationship','Claim relationship'));
        }
    }

    if(installer_table_exists($pdo,'research_entity_relations')){
        $q=$pdo->prepare("SELECT rr.public_id,rr.relation_type,rr.note,rr.created_at,rp.public_id project_public_id,
          se.public_id source_public_id,se.canonical_name source_name,te.public_id target_public_id,te.canonical_name target_name
          FROM research_entity_relations rr JOIN research_entities se ON se.id=rr.source_entity_id JOIN research_entities te ON te.id=rr.target_entity_id
          JOIN research_projects rp ON rp.id=rr.project_id WHERE rr.project_id=? ORDER BY rr.created_at DESC");
        $q->execute([$projectId]);
        foreach($q->fetchAll()?:[] as $row){
            $title=mb_substr((string)$row['source_name'].' '.str_replace('_',' ',(string)$row['relation_type']).' '.(string)$row['target_name'],0,255);
            $content="[ENTITY ".(string)$row['source_public_id']."] ".(string)$row['source_name']."\nRelationship: ".(string)$row['relation_type']."\n[ENTITY ".(string)$row['target_public_id']."] ".(string)$row['target_name'];
            if(trim((string)($row['note']??''))!=='')$content.="\nNote: ".trim((string)$row['note']);
            $records[]=research_retrieval_record('entity_relation',(string)$row['public_id'],$title,$content,null,(string)$row['created_at'],[
              'relation_type'=>(string)$row['relation_type'],'source_entity_id'=>(string)$row['source_public_id'],'target_entity_id'=>(string)$row['target_public_id'],'project_public_id'=>(string)$row['project_public_id']
            ],'ready',research_retrieval_chunk_text($content,'relationship','Entity relationship'));
        }
    }

    if(installer_table_exists($pdo,'research_tasks')){
        $q=$pdo->prepare("SELECT rt.public_id,rt.title,rt.description,rt.task_type,rt.priority,rt.status,rt.due_at,rt.updated_at,ra.public_id agent_public_id
          FROM research_tasks rt JOIN research_agents ra ON ra.id=rt.research_agent_id
          WHERE rt.project_id=? AND rt.status<>'archived' ORDER BY rt.updated_at DESC LIMIT 300");
        $q->execute([$projectId]);
        foreach($q->fetchAll()?:[] as $row){
            $content="Task type: ".(string)$row['task_type']."\nPriority: ".(string)$row['priority']."\nStatus: ".(string)$row['status']."\n".trim((string)($row['description']??''));
            $records[]=research_retrieval_record('task',(string)$row['public_id'],(string)$row['title'],$content,null,(string)$row['updated_at'],[
              'task_type'=>(string)$row['task_type'],'priority'=>(string)$row['priority'],'task_status'=>(string)$row['status'],'due_at'=>$row['due_at']??null,'agent_public_id'=>(string)$row['agent_public_id']
            ],'ready',research_retrieval_chunk_text($content,'task','Task'));
        }
    }

    if(installer_table_exists($pdo,'research_programs')){
        $q=$pdo->prepare("SELECT rp.public_id,rp.title,rp.objective,rp.status,rp.cadence,rp.next_run_at,rp.updated_at,ra.public_id agent_public_id
          FROM research_programs rp JOIN research_agents ra ON ra.id=rp.research_agent_id
          WHERE rp.project_id=? AND rp.status<>'archived' ORDER BY rp.updated_at DESC LIMIT 200");
        $q->execute([$projectId]);
        foreach($q->fetchAll()?:[] as $row){
            $content="Program status: ".(string)$row['status']."\nCadence: ".(string)$row['cadence']."\nObjective: ".(string)$row['objective'];
            if(!empty($row['next_run_at']))$content.="\nNext run: ".(string)$row['next_run_at'];
            $records[]=research_retrieval_record('program',(string)$row['public_id'],(string)$row['title'],$content,null,(string)$row['updated_at'],[
              'program_status'=>(string)$row['status'],'cadence'=>(string)$row['cadence'],'next_run_at'=>$row['next_run_at']??null,'agent_public_id'=>(string)$row['agent_public_id']
            ],'ready',research_retrieval_chunk_text($content,'program','Program'));
        }
    }

    return $records;
}

function research_retrieval_records_hash(array $records): string {
    $parts=[];foreach($records as $r)$parts[]=$r['object_type'].':'.$r['object_public_id'].':'.$r['content_hash'].':'.($r['folder_public_id']??'').':'.$r['source_status'].':'.hash('sha256',json_encode((array)($r['metadata']??[]),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    sort($parts,SORT_STRING);return hash('sha256',implode("\n",$parts));
}

function research_retrieval_queue_project(PDO $pdo,int $projectId): void {
    if(!research_retrieval_ready($pdo)||$projectId<1)return;
    $pdo->prepare("INSERT INTO research_retrieval_projects(project_id,status,queued_at) VALUES(?,'pending',NOW())
      ON DUPLICATE KEY UPDATE status=IF(status='indexing','indexing','pending'),queued_at=NOW(),last_error=NULL")->execute([$projectId]);
    $pdo->prepare("INSERT INTO research_retrieval_jobs(project_id,status,available_at,rerun_requested) VALUES(?,'queued',NOW(),0)
      ON DUPLICATE KEY UPDATE rerun_requested=IF(status='processing',1,0),status=IF(status='processing','processing','queued'),attempts=IF(status='processing',attempts,0),claim_token=IF(status='processing',claim_token,NULL),lease_expires_at=IF(status='processing',lease_expires_at,NULL),available_at=NOW(),last_error=NULL,completed_at=NULL")->execute([$projectId]);
}

function research_retrieval_embed_command(array $config): string {
    return trim((string)($config['research_retrieval']['embedding_command']??''));
}

function research_retrieval_embed_text(array $config,string $text): ?array {
    $command=research_retrieval_embed_command($config);if($command==='')return null;
    $input=tempnam(sys_get_temp_dir(),'annotated-embed-in-');$output=tempnam(sys_get_temp_dir(),'annotated-embed-out-');
    if($input===false||$output===false){if($input)@unlink($input);if($output)@unlink($output);throw new RuntimeException('Unable to create embedding temp files.');}
    try{
        file_put_contents($input,mb_substr($text,0,16000));
        $cmd=str_replace(['{input}','{output}'],[escapeshellarg($input),escapeshellarg($output)],$command).' 2>&1';$lines=[];$code=0;exec($cmd,$lines,$code);
        if($code!==0)throw new RuntimeException('Embedding command failed: '.implode("\n",array_slice($lines,-5)));
        $json=json_decode((string)file_get_contents($output),true);$vector=is_array($json)&&isset($json['embedding'])?$json['embedding']:$json;
        if(!is_array($vector)||count($vector)<2||count($vector)>8192)throw new RuntimeException('Embedding command returned an invalid vector.');
        $out=[];foreach($vector as $value){if(!is_numeric($value))throw new RuntimeException('Embedding vector contains a non-numeric value.');$f=(float)$value;if(is_nan($f)||is_infinite($f))throw new RuntimeException('Embedding vector contains a non-finite value.');$out[]=$f;}
        return $out;
    }finally{@unlink($input);@unlink($output);}
}

function research_retrieval_cosine(array $a,array $b): float {
    if(count($a)!==count($b)||count($a)===0)return 0.0;$dot=0.0;$aa=0.0;$bb=0.0;
    foreach($a as $i=>$v){$x=(float)$v;$y=(float)$b[$i];$dot+=$x*$y;$aa+=$x*$x;$bb+=$y*$y;}
    if($aa<=0||$bb<=0)return 0.0;return $dot/(sqrt($aa)*sqrt($bb));
}

function research_retrieval_rebuild_project(PDO $pdo,array $config,int $projectId,?array $records=null,bool $embed=false): array {
    if(!research_retrieval_ready($pdo))throw new RuntimeException('Unified Research retrieval requires the latest database upgrade.');
    $records=$records??research_retrieval_collect_records($pdo,$projectId);$stateHash=research_retrieval_records_hash($records);
    $pdo->prepare("INSERT INTO research_retrieval_projects(project_id,status,queued_at) VALUES(?,'indexing',NOW())
      ON DUPLICATE KEY UPDATE status='indexing',last_error=NULL")->execute([$projectId]);
    $seen=[];$pdo->beginTransaction();
    try{
        foreach($records as $record){
            $existingQ=$pdo->prepare("SELECT id,content_hash FROM research_retrieval_documents WHERE project_id=? AND object_type=? AND object_public_id=? LIMIT 1");
            $existingQ->execute([$projectId,$record['object_type'],$record['object_public_id']]);$existing=$existingQ->fetch()?:null;
            $chunksChanged=!$existing||!hash_equals((string)($existing['content_hash']??''),(string)$record['content_hash']);
            $pdo->prepare("INSERT INTO research_retrieval_documents(project_id,object_type,object_public_id,folder_public_id,title,source_status,content_hash,source_updated_at,metadata_json,indexed_at)
              VALUES(?,?,?,?,?,?,?,?,?,NOW())
              ON DUPLICATE KEY UPDATE folder_public_id=VALUES(folder_public_id),title=VALUES(title),source_status=VALUES(source_status),content_hash=VALUES(content_hash),source_updated_at=VALUES(source_updated_at),metadata_json=VALUES(metadata_json),indexed_at=NOW()")
              ->execute([$projectId,$record['object_type'],$record['object_public_id'],$record['folder_public_id'],$record['title'],$record['source_status'],$record['content_hash'],$record['source_updated_at']?:null,json_encode($record['metadata'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            if($existing)$documentId=(int)$existing['id'];
            else{$q=$pdo->prepare("SELECT id FROM research_retrieval_documents WHERE project_id=? AND object_type=? AND object_public_id=? LIMIT 1");$q->execute([$projectId,$record['object_type'],$record['object_public_id']]);$documentId=(int)$q->fetchColumn();}
            $seen[]=$documentId;
            if(!$chunksChanged)continue;
            $pdo->prepare('DELETE FROM research_retrieval_chunks WHERE document_id=?')->execute([$documentId]);$idx=0;
            foreach($record['chunks'] as $chunk){$idx++;$content=(string)$chunk['content'];$hash=hash('sha256',$content."\0".json_encode([$chunk['locator_type']??null,$chunk['locator_label']??null,$chunk['locator']??[],$chunk['heading']??null],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
                $pdo->prepare("INSERT INTO research_retrieval_chunks(document_id,chunk_index,locator_type,locator_label,locator_json,heading,content,content_hash,token_estimate,embedding_status)
                  VALUES(?,?,?,?,?,?,?,?,?,'none')")
                  ->execute([$documentId,$idx,(string)($chunk['locator_type']??'section'),mb_substr((string)($chunk['locator_label']??('Section '.$idx)),0,190),json_encode((array)($chunk['locator']??[]),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),isset($chunk['heading'])?mb_substr((string)$chunk['heading'],0,255):null,$content,$hash,max(1,(int)ceil(mb_strlen($content)/4))]);
            }
        }
        if($seen){$marks=implode(',',array_fill(0,count($seen),'?'));$pdo->prepare("DELETE FROM research_retrieval_documents WHERE project_id=? AND id NOT IN ($marks)")->execute(array_merge([$projectId],$seen));}
        else $pdo->prepare('DELETE FROM research_retrieval_documents WHERE project_id=?')->execute([$projectId]);
        $q=$pdo->prepare('SELECT COUNT(*),(SELECT COUNT(*) FROM research_retrieval_chunks c JOIN research_retrieval_documents d ON d.id=c.document_id WHERE d.project_id=?) FROM research_retrieval_documents WHERE project_id=?');$q->execute([$projectId,$projectId]);$counts=$q->fetch(PDO::FETCH_NUM)?:[0,0];
        $pdo->prepare("INSERT INTO research_retrieval_projects(project_id,state_hash,status,document_count,chunk_count,last_error,indexed_at)
          VALUES(?,?,'ready',?,?,NULL,NOW())
          ON DUPLICATE KEY UPDATE state_hash=VALUES(state_hash),status='ready',document_count=VALUES(document_count),chunk_count=VALUES(chunk_count),last_error=NULL,indexed_at=NOW()")
          ->execute([$projectId,$stateHash,(int)$counts[0],(int)$counts[1]]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$pdo->prepare("UPDATE research_retrieval_projects SET status='failed',last_error=? WHERE project_id=?")->execute([mb_substr($e->getMessage(),0,1000),$projectId]);throw $e;}
    if($embed&&research_retrieval_embed_command($config)!=='')research_retrieval_embed_pending($pdo,$config,$projectId,80);
    return ['project_id'=>$projectId,'state_hash'=>$stateHash,'documents'=>count($records)];
}

function research_retrieval_embed_pending(PDO $pdo,array $config,int $projectId,int $limit=40): int {
    if(research_retrieval_embed_command($config)==='')return 0;$limit=max(1,min(200,$limit));
    $q=$pdo->prepare("SELECT c.id,c.content FROM research_retrieval_chunks c JOIN research_retrieval_documents d ON d.id=c.document_id WHERE d.project_id=? AND c.embedding_status='none' ORDER BY c.id LIMIT ".$limit);$q->execute([$projectId]);$done=0;
    foreach($q->fetchAll()?:[] as $row){
        try{$vector=research_retrieval_embed_text($config,(string)$row['content']);if(!$vector)continue;
            $pdo->prepare("UPDATE research_retrieval_chunks SET embedding_status='ready',embedding_provider=?,embedding_model=?,embedding_dimensions=?,embedding_json=?,updated_at=NOW() WHERE id=?")
              ->execute([(string)($config['research_retrieval']['embedding_provider']??'command'),(string)($config['research_retrieval']['embedding_model']??'local'),count($vector),json_encode($vector,JSON_PRESERVE_ZERO_FRACTION),(int)$row['id']]);$done++;
        }catch(Throwable $e){$pdo->prepare("UPDATE research_retrieval_chunks SET embedding_status='failed',updated_at=NOW() WHERE id=?")->execute([(int)$row['id']]);}
    }
    return $done;
}

function research_retrieval_ensure_current(PDO $pdo,array $config,array $viewer,string $projectPublicId): array {
    $project=project_access($pdo,(int)$viewer['id'],$projectPublicId);if(!$project)throw new RuntimeException('Research project not found.');
    if(!research_retrieval_ready($pdo))throw new RuntimeException('Unified Research retrieval requires the latest database upgrade.');
    $records=research_retrieval_collect_records($pdo,(int)$project['id']);$hash=research_retrieval_records_hash($records);
    $q=$pdo->prepare('SELECT * FROM research_retrieval_projects WHERE project_id=?');$q->execute([(int)$project['id']]);$state=$q->fetch();
    if(!$state||!hash_equals((string)($state['state_hash']??''),$hash)||($state['status']??'')!=='ready')research_retrieval_rebuild_project($pdo,$config,(int)$project['id'],$records,false);
    $q=$pdo->prepare('SELECT * FROM research_retrieval_projects WHERE project_id=?');$q->execute([(int)$project['id']]);$state=$q->fetch()?:[];
    return ['project'=>$project,'state'=>$state];
}

function research_retrieval_folder_scope(PDO $pdo,int $projectId,string $folderPublicId): array {
    $folderPublicId=trim($folderPublicId);if($folderPublicId==='')return [];
    $q=$pdo->prepare("SELECT id,public_id FROM research_workspace_objects WHERE project_id=? AND public_id=? AND object_type='folder' AND status='active' LIMIT 1");$q->execute([$projectId,$folderPublicId]);$root=$q->fetch();if(!$root)return ['__missing__'];
    $ids=[(int)$root['id']];$public=[(string)$root['public_id']];$frontier=$ids;
    while($frontier){$marks=implode(',',array_fill(0,count($frontier),'?'));$q=$pdo->prepare("SELECT id,public_id FROM research_workspace_objects WHERE project_id=? AND object_type='folder' AND status='active' AND parent_id IN ($marks)");$q->execute(array_merge([$projectId],$frontier));$frontier=[];foreach($q->fetchAll()?:[] as $row){$ids[]=(int)$row['id'];$frontier[]=(int)$row['id'];$public[]=(string)$row['public_id'];}}
    return $public;
}

function research_retrieval_annotation_allowed(PDO $pdo,array $viewer,string $publicId): bool {
    $uid=(int)($viewer['id']??0);$admin=(($viewer['role']??'')==='admin');
    $q=$pdo->prepare("SELECT a.user_id,a.visibility,a.team_id,a.status,COALESCE(s.moderation_status,'visible') source_moderation_status
      FROM annotations a JOIN sources s ON s.id=a.source_id WHERE a.public_id=? LIMIT 1");
    $q->execute([$publicId]);$row=$q->fetch();if(!$row)return false;
    if(($row['source_moderation_status']??'visible')==='restricted'&&!$admin)return false;
    if($admin||(int)$row['user_id']===$uid)return true;
    if(($row['status']??'')!=='published')return false;
    if(($row['visibility']??'')==='public')return true;
    if(($row['visibility']??'')==='team'&&!empty($row['team_id'])){
        $m=$pdo->prepare('SELECT 1 FROM team_members WHERE team_id=? AND user_id=? LIMIT 1');$m->execute([(int)$row['team_id'],$uid]);
        return (bool)$m->fetchColumn();
    }
    return false;
}

function research_retrieval_project_object_allowed(PDO $pdo,array $viewer,string $type,string $publicId): bool {
    $table=match($type){
      'claim'=>'research_claims','finding'=>'research_findings','entity'=>'research_entities','claim_relation'=>'claim_relations','entity_relation'=>'research_entity_relations','task'=>'research_tasks','program'=>'research_programs','report'=>'research_system_reports',default=>''
    };
    if($table==='')return false;
    try{
        $q=$pdo->prepare("SELECT rp.public_id FROM {$table} o JOIN research_projects rp ON rp.id=o.project_id WHERE o.public_id=? LIMIT 1");
        $q->execute([$publicId]);$projectPublic=(string)($q->fetchColumn()?:'');
        return $projectPublic!==''&&project_access($pdo,(int)$viewer['id'],$projectPublic)!==null;
    }catch(Throwable $e){return false;}
}

function research_retrieval_result_allowed(PDO $pdo,array $viewer,array $row): bool {
    $type=(string)($row['object_type']??'');$id=(string)($row['object_public_id']??'');
    if($type==='annotation')return research_retrieval_annotation_allowed($pdo,$viewer,$id);
    if(in_array($type,['document','bookmark','sticky','upload','recording'],true))return research_agent_workspace_object($pdo,$viewer,$id,false)!==null;
    if($type==='source')return source_access($pdo,$id,$viewer)!==null;
    if($type==='report')return function_exists('research_system_report_access')&&research_system_report_access($pdo,$viewer,$id)!==null;
    if(in_array($type,['claim','finding','entity','claim_relation','entity_relation','task','program'],true))return research_retrieval_project_object_allowed($pdo,$viewer,$type,$id);
    return false;
}

function research_retrieval_href(array $row): string {
    $metadata=json_decode((string)($row['metadata_json']??''),true)?:[];$id=(string)$row['object_public_id'];
    return match((string)$row['object_type']){
      'annotation'=>'/annotation.php?id='.rawurlencode($id),
      'source'=>'/source.php?id='.rawurlencode($id),
      'upload'=>'/research-workspace-file.php?id='.rawurlencode($id),
      'bookmark'=>(string)($metadata['url']??''),
      'claim'=>'/research-claim.php?id='.rawurlencode($id),
      'finding'=>'/research-finding.php?id='.rawurlencode($id),
      'entity'=>'/research-entity.php?id='.rawurlencode($id),
      'claim_relation'=>!empty($metadata['project_public_id'])?'/research-graph.php?id='.rawurlencode((string)$metadata['project_public_id']):'',
      'entity_relation'=>!empty($metadata['project_public_id'])?'/research-entities.php?id='.rawurlencode((string)$metadata['project_public_id']):'',
      'task'=>!empty($metadata['agent_public_id'])?'/research-tasks.php?agent='.rawurlencode((string)$metadata['agent_public_id']).'&task='.rawurlencode($id):'',
      'program'=>!empty($metadata['agent_public_id'])?'/research-programs.php?agent='.rawurlencode((string)$metadata['agent_public_id']).'&program='.rawurlencode($id):'',
      'report'=>!empty($metadata['agent_public_id'])?'/research-reports.php?agent='.rawurlencode((string)$metadata['agent_public_id']).'&report='.rawurlencode($id):'',
      'document'=>!empty($metadata['source_report_id'])?'/home.php?agent='.rawurlencode((string)($metadata['agent_conversation_id']??'')).'&doc='.rawurlencode($id):'',
      default=>''
    };
}

function research_retrieval_snippet(string $content,string $query,int $max=360): string {
    $content=research_retrieval_normalize($content,20000);if($content==='')return '';
    $needle=mb_strtolower(trim($query));$at=$needle!==''?mb_stripos(mb_strtolower($content),$needle):false;
    if($at===false)return mb_substr($content,0,$max);
    $start=max(0,(int)$at-(int)floor($max/3));return ($start>0?'…':'').mb_substr($content,$start,$max).($start+$max<mb_strlen($content)?'…':'');
}

function research_retrieval_search(PDO $pdo,array $config,array $viewer,string $projectPublicId,string $query,array $filters=[],int $limit=30,bool $audit=true): array {
    $current=research_retrieval_ensure_current($pdo,$config,$viewer,$projectPublicId);$project=$current['project'];$state=$current['state'];$projectId=(int)$project['id'];
    $query=mb_substr(trim((string)preg_replace('/\s+/u',' ',$query)),0,1000);$limit=max(1,min(60,$limit));
    $type=strtolower(trim((string)($filters['type']??'all')));$allowedTypes=['all','source','annotation','document','bookmark','sticky','upload','recording','transcript','claim','finding','entity','relation','task','program','report'];if(!in_array($type,$allowedTypes,true))$type='all';
    $folder=trim((string)($filters['folder_id']??''));$status=trim((string)($filters['status']??''));$dateFrom=trim((string)($filters['date_from']??''));$dateTo=trim((string)($filters['date_to']??''));$creator=trim((string)($filters['creator']??''));$excludeReportDerivatives=!empty($filters['exclude_report_derivatives']);$stableScoring=!empty($filters['stable_scoring']);
    $folderScope=$folder!==''?research_retrieval_folder_scope($pdo,$projectId,$folder):[];

    $params=[$projectId];$where=['d.project_id=?'];
    if($excludeReportDerivatives)$where[]="d.object_type<>'report' AND NOT (d.object_type='document' AND JSON_UNQUOTE(JSON_EXTRACT(d.metadata_json,'$.source_report_id')) IS NOT NULL)";
    if($type==='transcript'){$where[]="d.object_type='recording'";$where[]="d.source_status='ready'";}
    elseif($type==='report'){$where[]="d.object_type='report'";}
    elseif($type==='relation'){$where[]="d.object_type IN ('claim_relation','entity_relation')";}
    elseif($type!=='all'){$where[]='d.object_type=?';$params[]=$type;}
    if($status!==''){$where[]='d.source_status=?';$params[]=$status;}
    if($dateFrom!==''&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFrom)){$where[]='d.source_updated_at>=?';$params[]=$dateFrom.' 00:00:00';}
    if($dateTo!==''&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateTo)){$where[]='d.source_updated_at<?';$params[]=date('Y-m-d H:i:s',strtotime($dateTo.' +1 day'));}
    if($folderScope){$marks=implode(',',array_fill(0,count($folderScope),'?'));$where[]="d.folder_public_id IN ($marks)";$params=array_merge($params,$folderScope);}

    $rows=[];$mode='browse';
    if($query===''){
        $sql="SELECT d.*,c.id chunk_id,c.chunk_index,c.locator_type,c.locator_label,c.locator_json,c.heading,c.content,0 lexical_score,c.embedding_json
          FROM research_retrieval_documents d LEFT JOIN research_retrieval_chunks c ON c.id=(SELECT c2.id FROM research_retrieval_chunks c2 WHERE c2.document_id=d.id ORDER BY c2.chunk_index LIMIT 1)
          WHERE ".implode(' AND ',$where)." ORDER BY COALESCE(d.source_updated_at,d.updated_at) DESC,d.id DESC LIMIT ".($limit*3);
        $q=$pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll()?:[];
    }else{
        $mode=$stableScoring?'stable_lexical':'lexical';$like='%'.$query.'%';$searchWhere=$where;
        if($stableScoring){
            $searchWhere[]="(c.content LIKE ? OR c.heading LIKE ? OR d.title LIKE ?)";
            $searchParams=array_merge($params,[$like,$like,$like]);
            $sql="SELECT d.*,c.id chunk_id,c.chunk_index,c.locator_type,c.locator_label,c.locator_json,c.heading,c.content,
              ((CASE WHEN d.title LIKE ? THEN 4 ELSE 0 END)+(CASE WHEN c.heading LIKE ? THEN 2 ELSE 0 END)+(CASE WHEN c.content LIKE ? THEN 1.5 ELSE 0 END)) lexical_score,c.embedding_json
              FROM research_retrieval_documents d LEFT JOIN research_retrieval_chunks c ON c.document_id=d.id
              WHERE ".implode(' AND ',$searchWhere)." ORDER BY lexical_score DESC,COALESCE(d.source_updated_at,d.updated_at) DESC,d.id DESC,c.chunk_index ASC LIMIT ".($limit*8);
            $q=$pdo->prepare($sql);$q->execute(array_merge([$like,$like,$like],$searchParams));$rows=$q->fetchAll()?:[];
        }else{
            $searchWhere[]="(COALESCE(MATCH(c.heading,c.content) AGAINST (? IN NATURAL LANGUAGE MODE),0)>0 OR c.content LIKE ? OR c.heading LIKE ? OR d.title LIKE ?)";
            $searchParams=array_merge($params,[$query,$like,$like,$like]);
            $sql="SELECT d.*,c.id chunk_id,c.chunk_index,c.locator_type,c.locator_label,c.locator_json,c.heading,c.content,
              (COALESCE(MATCH(c.heading,c.content) AGAINST (? IN NATURAL LANGUAGE MODE),0)+(CASE WHEN d.title LIKE ? THEN 4 ELSE 0 END)+(CASE WHEN c.content LIKE ? THEN 1.5 ELSE 0 END)) lexical_score,c.embedding_json
              FROM research_retrieval_documents d LEFT JOIN research_retrieval_chunks c ON c.document_id=d.id
              WHERE ".implode(' AND ',$searchWhere)." ORDER BY lexical_score DESC,COALESCE(d.source_updated_at,d.updated_at) DESC LIMIT ".($limit*8);
            $q=$pdo->prepare($sql);$q->execute(array_merge([$query,$like,$like],$searchParams));$rows=$q->fetchAll()?:[];
        }
    }

    $queryVector=null;$semanticRows=[];
    if($query!==''&&research_retrieval_embed_command($config)!==''){
        try{
            $queryVector=research_retrieval_embed_text($config,$query);
            if($queryVector){
                $semanticLimit=max(300,min(2000,$limit*30));
                $semanticWhere=$where;$semanticWhere[]="c.embedding_status='ready'";$semanticWhere[]='c.embedding_json IS NOT NULL';
                $sql="SELECT d.*,c.id chunk_id,c.chunk_index,c.locator_type,c.locator_label,c.locator_json,c.heading,c.content,0 lexical_score,c.embedding_json
                  FROM research_retrieval_documents d JOIN research_retrieval_chunks c ON c.document_id=d.id
                  WHERE ".implode(' AND ',$semanticWhere)." ORDER BY COALESCE(d.source_updated_at,d.updated_at) DESC,c.id DESC LIMIT ".$semanticLimit;
                $q=$pdo->prepare($sql);$q->execute($params);$semanticRows=$q->fetchAll()?:[];
                if($semanticRows)$mode='hybrid';
            }
        }catch(Throwable $e){$queryVector=null;$semanticRows=[];}
    }
    if($semanticRows){
        $seenChunks=[];foreach($rows as $row)if(!empty($row['chunk_id']))$seenChunks[(string)$row['chunk_id']]=true;
        foreach($semanticRows as $row){$key=(string)($row['chunk_id']??'');if($key!==''&&isset($seenChunks[$key]))continue;$rows[]=$row;if($key!=='')$seenChunks[$key]=true;}
    }

    $best=[];foreach($rows as $row){
        if(!research_retrieval_result_allowed($pdo,$viewer,$row))continue;
        $metadata=json_decode((string)($row['metadata_json']??''),true)?:[];
        if($creator!==''&&(string)($metadata['creator_public_id']??'')!==$creator)continue;
        $lex=max(0,(float)($row['lexical_score']??0));$semantic=0.0;
        if($queryVector&&!empty($row['embedding_json'])){$v=json_decode((string)$row['embedding_json'],true);if(is_array($v))$semantic=max(-1,min(1,research_retrieval_cosine($queryVector,$v)));}
        $score=$query===''?0:($mode==='hybrid'?(0.72*$lex+0.28*max(0,$semantic)*10):$lex);
        $key=$row['object_type'].':'.$row['object_public_id'];if(isset($best[$key])&&$best[$key]['score']>=$score)continue;
        $locator=(string)($row['locator_label']??'');$result=[
          'object_type'=>(string)$row['object_type'],'public_id'=>(string)$row['object_public_id'],'title'=>(string)$row['title'],
          'folder_public_id'=>$row['folder_public_id']??null,'source_status'=>(string)$row['source_status'],'updated_at'=>$row['source_updated_at']??$row['updated_at'],
          'metadata'=>$metadata,'score'=>$score,'semantic_score'=>$semantic,'locator_type'=>$row['locator_type']??null,'locator_label'=>$locator?:null,
          'locator'=>json_decode((string)($row['locator_json']??''),true)?:[],'heading'=>$row['heading']??null,
          'snippet'=>research_retrieval_snippet((string)($row['content']??''),$query),'href'=>research_retrieval_href($row),
          'citation'=>['type'=>(string)$row['object_type'],'id'=>(string)$row['object_public_id'],'locator'=>$locator?:null,'label'=>(string)$row['title'].($locator!==''?' · '.$locator:'')]
        ];
        $best[$key]=$result;
    }
    $results=array_values($best);usort($results,function($a,$b)use($query){if($query==='')return strcmp((string)$b['updated_at'],(string)$a['updated_at']);return ($b['score']<=>$a['score'])?:strcmp((string)$b['updated_at'],(string)$a['updated_at']);});$results=array_slice($results,0,$limit);

    if($audit){
        $public=ulid_like();$refs=array_map(fn($r)=>$r['citation'],$results);
        try{$pdo->prepare("INSERT INTO research_retrieval_queries(public_id,project_id,user_id,query_hash,query_text,retrieval_mode,filters_json,result_refs_json,result_count) VALUES(?,?,?,?,?,?,?,?,?)")
          ->execute([$public,$projectId,(int)$viewer['id'],hash('sha256',$query.'|'.json_encode($filters)),mb_substr($query,0,1000),$mode,json_encode($filters,JSON_UNESCAPED_SLASHES),json_encode($refs,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),count($results)]);}catch(Throwable $e){}
    }
    $semanticConfigured=research_retrieval_embed_command($config)!=='';$embeddedCount=0;
    if($semanticConfigured){$eq=$pdo->prepare("SELECT COUNT(*) FROM research_retrieval_chunks c JOIN research_retrieval_documents d ON d.id=c.document_id WHERE d.project_id=? AND c.embedding_status='ready'");$eq->execute([$projectId]);$embeddedCount=(int)$eq->fetchColumn();}
    $chunkCount=(int)($state['chunk_count']??0);
    return ['project'=>['public_id'=>$projectPublicId,'title'=>$project['title']],'query'=>$query,'mode'=>$mode,'filters'=>$filters,'index'=>[
      'status'=>$state['status']??'ready','indexed_at'=>$state['indexed_at']??null,'input_hash'=>$state['state_hash']??null,'document_count'=>(int)($state['document_count']??0),'chunk_count'=>$chunkCount,
      'semantic_available'=>$semanticConfigured,'embedded_chunk_count'=>$embeddedCount,'semantic_ready'=>$semanticConfigured&&$chunkCount>0&&$embeddedCount>=$chunkCount
    ],'results'=>$results];
}

function research_retrieval_context(PDO $pdo,array $config,array $viewer,string $projectPublicId,string $query,array $filters=[],int $limit=10): array {
    $search=research_retrieval_search($pdo,$config,$viewer,$projectPublicId,$query,$filters,$limit,false);$parts=['[UNIFIED RESEARCH RETRIEVAL]'];$refs=[];
    foreach($search['results'] as $result){
        $citation='['.strtoupper($result['object_type']).' '.$result['public_id'].($result['locator_label']?' · '.$result['locator_label']:'').']';
        $parts[]=$citation."\n".$result['title']."\n".$result['snippet'];$refs[]=['type'=>$result['object_type'],'id'=>$result['public_id'],'locator'=>$result['locator']??[],'label'=>$result['citation']['label']];
    }
    return ['text'=>mb_substr(implode("\n\n",$parts),0,22000),'refs'=>$refs,'mode'=>$search['mode'],'results'=>$search['results'],'index'=>$search['index']];
}

function research_retrieval_related(PDO $pdo,array $config,array $viewer,string $projectPublicId,string $type,string $publicId,int $limit=8): array {
    $current=research_retrieval_ensure_current($pdo,$config,$viewer,$projectPublicId);
    if(!research_retrieval_result_allowed($pdo,$viewer,['object_type'=>$type,'object_public_id'=>$publicId]))return [];
    $q=$pdo->prepare("SELECT d.title,c.content FROM research_retrieval_documents d LEFT JOIN research_retrieval_chunks c ON c.document_id=d.id WHERE d.project_id=? AND d.object_type=? AND d.object_public_id=? ORDER BY c.chunk_index LIMIT 2");$q->execute([(int)$current['project']['id'],$type,$publicId]);$rows=$q->fetchAll()?:[];
    if(!$rows)return [];$seed=trim(implode(' ',array_map(fn($r)=>(string)$r['title'].' '.mb_substr((string)$r['content'],0,600),$rows)));
    $terms=preg_split('/[^\pL\pN]+/u',mb_strtolower($seed))?:[];$stop=array_flip(['the','and','that','this','with','from','have','for','you','are','was','were','but','not','into','about','your','research']);
    $freq=[];foreach($terms as $term)if(mb_strlen($term)>=4&&!isset($stop[$term]))$freq[$term]=($freq[$term]??0)+1;arsort($freq);$query=implode(' ',array_slice(array_keys($freq),0,6));if($query==='')$query=(string)$rows[0]['title'];
    $results=research_retrieval_search($pdo,$config,$viewer,$projectPublicId,$query,[],$limit+3,false)['results'];
    return array_slice(array_values(array_filter($results,fn($r)=>!($r['object_type']===$type&&$r['public_id']===$publicId))),0,$limit);
}
