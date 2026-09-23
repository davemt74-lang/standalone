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
    $hash=hash('sha256',$type."\0".$publicId."\0".$title."\0".$content."\0".json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    return ['object_type'=>$type,'object_public_id'=>$publicId,'title'=>$title,'content'=>$content,'folder_public_id'=>$folderPublicId?:null,'source_updated_at'=>$updatedAt,'metadata'=>$metadata,'source_status'=>$sourceStatus,'content_hash'=>$hash,'chunks'=>$chunks];
}

function research_retrieval_collect_records(PDO $pdo,int $projectId): array {
    $records=[];

    $q=$pdo->prepare("SELECT s.public_id,s.title,s.canonical_url,s.domain,s.updated_at,sv.version_number,sv.captured_at,sv.extracted_text
      FROM project_sources ps JOIN sources s ON s.id=ps.source_id LEFT JOIN source_versions sv ON sv.id=s.current_version_id
      WHERE ps.project_id=? ORDER BY ps.created_at,s.id");
    $q->execute([$projectId]);
    foreach($q->fetchAll()?:[] as $row){
        $title=(string)($row['title']?:$row['domain']?:$row['canonical_url']?:'Source');
        $content=(string)($row['extracted_text']??'');
        $records[] = research_retrieval_record('source',(string)$row['public_id'],$title,$content,null,(string)($row['captured_at']?:$row['updated_at']?:''),[
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
      LEFT JOIN research_workspace_objects folder ON folder.id=rdp.folder_object_id AND folder.status='active'
      WHERE pa.project_id=? AND a.status NOT IN ('removed') ORDER BY pa.created_at,a.id");
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
    return $records;
}

function research_retrieval_records_hash(array $records): string {
    $parts=[];foreach($records as $r)$parts[]=$r['object_type'].':'.$r['object_public_id'].':'.$r['content_hash'].':'.($r['folder_public_id']??'').':'.$r['source_status'];
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
            $pdo->prepare("INSERT INTO research_retrieval_documents(project_id,object_type,object_public_id,folder_public_id,title,source_status,content_hash,source_updated_at,metadata_json,indexed_at)
              VALUES(?,?,?,?,?,?,?,?,?,NOW())
              ON DUPLICATE KEY UPDATE folder_public_id=VALUES(folder_public_id),title=VALUES(title),source_status=VALUES(source_status),content_hash=VALUES(content_hash),source_updated_at=VALUES(source_updated_at),metadata_json=VALUES(metadata_json),indexed_at=NOW()")
              ->execute([$projectId,$record['object_type'],$record['object_public_id'],$record['folder_public_id'],$record['title'],$record['source_status'],$record['content_hash'],$record['source_updated_at']?:null,json_encode($record['metadata'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            $q=$pdo->prepare("SELECT id FROM research_retrieval_documents WHERE project_id=? AND object_type=? AND object_public_id=? LIMIT 1");$q->execute([$projectId,$record['object_type'],$record['object_public_id']]);$documentId=(int)$q->fetchColumn();$seen[]=$documentId;
            $pdo->prepare('DELETE FROM research_retrieval_chunks WHERE document_id=?')->execute([$documentId]);$idx=0;
            foreach($record['chunks'] as $chunk){$idx++;$content=(string)$chunk['content'];$hash=hash('sha256',$content);
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

function research_retrieval_result_allowed(PDO $pdo,array $viewer,array $row): bool {
    $type=(string)($row['object_type']??'');$id=(string)($row['object_public_id']??'');
    if($type==='annotation')return annotation_access($pdo,$id,$viewer)!==null;
    if(in_array($type,['document','bookmark','sticky','upload','recording'],true))return research_agent_workspace_object($pdo,$viewer,$id,false)!==null;
    if($type==='source')return source_access($pdo,$id,$viewer)!==null;
    return false;
}

function research_retrieval_href(array $row): string {
    return match((string)$row['object_type']){
      'annotation'=>'/annotation.php?id='.rawurlencode((string)$row['object_public_id']),
      'source'=>'/source.php?id='.rawurlencode((string)$row['object_public_id']),
      'upload'=>'/research-workspace-file.php?id='.rawurlencode((string)$row['object_public_id']),
      'bookmark'=>(string)((json_decode((string)($row['metadata_json']??''),true)?:[])['url']??''),
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
    $type=strtolower(trim((string)($filters['type']??'all')));$allowedTypes=['all','source','annotation','document','bookmark','sticky','upload','recording','transcript'];if(!in_array($type,$allowedTypes,true))$type='all';
    $folder=trim((string)($filters['folder_id']??''));$status=trim((string)($filters['status']??''));$dateFrom=trim((string)($filters['date_from']??''));$dateTo=trim((string)($filters['date_to']??''));$creator=trim((string)($filters['creator']??''));
    $folderScope=$folder!==''?research_retrieval_folder_scope($pdo,$projectId,$folder):[];

    $params=[$projectId];$where=['d.project_id=?'];
    if($type==='transcript'){$where[]="d.object_type='recording'";$where[]="d.source_status='ready'";}
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
        $mode='lexical';$like='%'.$query.'%';$searchWhere=$where;$searchWhere[]="(MATCH(c.heading,c.content) AGAINST (? IN NATURAL LANGUAGE MODE)>0 OR c.content LIKE ? OR c.heading LIKE ? OR d.title LIKE ?)";
        $searchParams=array_merge($params,[$query,$like,$like,$like]);
        $sql="SELECT d.*,c.id chunk_id,c.chunk_index,c.locator_type,c.locator_label,c.locator_json,c.heading,c.content,
          (MATCH(c.heading,c.content) AGAINST (? IN NATURAL LANGUAGE MODE)+(CASE WHEN d.title LIKE ? THEN 4 ELSE 0 END)+(CASE WHEN c.content LIKE ? THEN 1.5 ELSE 0 END)) lexical_score,c.embedding_json
          FROM research_retrieval_documents d JOIN research_retrieval_chunks c ON c.document_id=d.id
          WHERE ".implode(' AND ',$searchWhere)." ORDER BY lexical_score DESC,COALESCE(d.source_updated_at,d.updated_at) DESC LIMIT ".($limit*8);
        $q=$pdo->prepare($sql);$q->execute(array_merge([$query,$like,$like],$searchParams));$rows=$q->fetchAll()?:[];
    }

    $queryVector=null;if($query!==''&&research_retrieval_embed_command($config)!==''){try{$queryVector=research_retrieval_embed_text($config,$query);if($queryVector)$mode='hybrid';}catch(Throwable $e){}}
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
      'status'=>$state['status']??'ready','indexed_at'=>$state['indexed_at']??null,'document_count'=>(int)($state['document_count']??0),'chunk_count'=>$chunkCount,
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
