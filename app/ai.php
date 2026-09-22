<?php
declare(strict_types=1);

function ai_crypto_key(array $config): string {
    $raw=(string)($config['app']['encryption_key']??'');
    if(strlen($raw)<24) throw new RuntimeException('Set app.encryption_key in config.php before storing AI API keys.');
    return hash('sha256',$raw,true);
}
function ai_encrypt_secret(array $config,string $plain): string {
    if($plain==='')return '';$key=ai_crypto_key($config);$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'annotated-ai');
    if($cipher===false)throw new RuntimeException('Unable to encrypt AI secret.');return base64_encode($iv.$tag.$cipher);
}
function ai_decrypt_secret(array $config,?string $encoded): string {
    if(!$encoded)return '';$raw=base64_decode($encoded,true);if($raw===false||strlen($raw)<29)throw new RuntimeException('Invalid AI secret.');$iv=substr($raw,0,12);$tag=substr($raw,12,16);$cipher=substr($raw,28);$plain=openssl_decrypt($cipher,'aes-256-gcm',ai_crypto_key($config),OPENSSL_RAW_DATA,$iv,$tag,'annotated-ai');if($plain===false)throw new RuntimeException('Unable to decrypt AI secret.');return $plain;
}
function ai_http_json(string $url,array $headers,array $body,int $timeout=45): array {
    $ch=curl_init($url);$headers[]='Content-Type: application/json';curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body,JSON_UNESCAPED_SLASHES),CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS|CURLPROTO_HTTP]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);if($raw===false)throw new RuntimeException('AI provider connection failed'.($err?': '.$err:''));$json=json_decode($raw,true);if($status<200||$status>=300){$msg=is_array($json)?($json['error']['message']??$json['message']??'AI provider request failed'):'AI provider request failed';throw new RuntimeException((string)$msg);}if(!is_array($json))throw new RuntimeException('Invalid AI provider response.');return $json;
}
function ai_model_record(PDO $pdo,int $modelId): array {
    $q=$pdo->prepare('SELECT m.*,p.label provider_label,p.provider_type,p.api_base_url,p.api_key_ciphertext,p.enabled provider_enabled FROM ai_models m JOIN ai_providers p ON p.id=m.provider_id WHERE m.id=?');$q->execute([$modelId]);$r=$q->fetch();if(!$r||!(int)$r['enabled']||!(int)$r['provider_enabled'])throw new RuntimeException('AI model is disabled or unavailable.');return $r;
}
function ai_setting_model_id(PDO $pdo,string $task,bool $pro=false): int {
    $map=['admin'=>'admin_default_model_id','pro'=>'pro_default_model_id','source_monitor'=>'source_monitor_model_id','moderation'=>'moderation_model_id','research'=>'research_model_id','transcript_cleanup'=>'transcript_cleanup_model_id','annotation_intelligence'=>'annotation_intelligence_model_id'];
    $routeKey=isset($map[$task])?$task:($pro?'pro':'admin');$col=$map[$routeKey];$q=$pdo->query("SELECT $col FROM ai_settings WHERE id=1");$id=(int)($q->fetchColumn()?:0);
    if(!$id&&$pro){$routeKey='pro';$id=(int)($pdo->query('SELECT pro_default_model_id FROM ai_settings WHERE id=1')->fetchColumn()?:0);}
    if(!$id&&!$pro){$routeKey='admin';$id=(int)($pdo->query('SELECT admin_default_model_id FROM ai_settings WHERE id=1')->fetchColumn()?:0);}
    if($id&&function_exists('data_model_deployment_resolve_route'))$id=data_model_deployment_resolve_route($pdo,$routeKey,$id);
    return $id;
}
function ai_extract_openai_text(array $j): string {
    if(isset($j['output_text'])&&is_string($j['output_text']))return trim($j['output_text']);$parts=[];foreach(($j['output']??[]) as $item)foreach(($item['content']??[]) as $c){if(isset($c['text'])&&is_string($c['text']))$parts[]=$c['text'];}return trim(implode("\n",$parts));
}
function ai_generate(PDO $pdo,array $config,int $modelId,string $system,string $prompt,?int $maxTokens=null): array {
    $m=ai_model_record($pdo,$modelId);$key=ai_decrypt_secret($config,$m['api_key_ciphertext']);if($key===''&&$m['provider_type']!=='openai_compatible')throw new RuntimeException('AI provider API key is not configured.');$max=$maxTokens?:max(256,min(16384,(int)$m['max_output_tokens']));$type=$m['provider_type'];$base=rtrim((string)($m['api_base_url']??''),'/');$j=[];$text='';$in=null;$out=null;
    if($type==='openai'){$url=($base?:'https://api.openai.com').'/v1/responses';$j=ai_http_json($url,['Authorization: Bearer '.$key],['model'=>$m['model_name'],'instructions'=>$system,'input'=>$prompt,'max_output_tokens'=>$max]);$text=ai_extract_openai_text($j);$in=$j['usage']['input_tokens']??null;$out=$j['usage']['output_tokens']??null;}
    elseif($type==='anthropic'){$url=($base?:'https://api.anthropic.com').'/v1/messages';$j=ai_http_json($url,['x-api-key: '.$key,'anthropic-version: 2023-06-01'],['model'=>$m['model_name'],'max_tokens'=>$max,'system'=>$system,'messages'=>[['role'=>'user','content'=>$prompt]]]);$chunks=[];foreach(($j['content']??[]) as $c)if(($c['type']??'')==='text')$chunks[]=(string)($c['text']??'');$text=trim(implode("\n",$chunks));$in=$j['usage']['input_tokens']??null;$out=$j['usage']['output_tokens']??null;}
    elseif($type==='gemini'){$url=($base?:'https://generativelanguage.googleapis.com').'/v1beta/models/'.rawurlencode((string)$m['model_name']).':generateContent';$j=ai_http_json($url,['x-goog-api-key: '.$key],['systemInstruction'=>['parts'=>[['text'=>$system]]],'contents'=>[['role'=>'user','parts'=>[['text'=>$prompt]]]],'generationConfig'=>['maxOutputTokens'=>$max]]);$chunks=[];foreach(($j['candidates'][0]['content']['parts']??[]) as $p)if(isset($p['text']))$chunks[]=(string)$p['text'];$text=trim(implode("\n",$chunks));$in=$j['usageMetadata']['promptTokenCount']??null;$out=$j['usageMetadata']['candidatesTokenCount']??null;}
    else{$url=($base?:'http://127.0.0.1:11434').'/v1/chat/completions';$headers=$key!==''?['Authorization: Bearer '.$key]:[];$j=ai_http_json($url,$headers,['model'=>$m['model_name'],'messages'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>$prompt]],'max_tokens'=>$max]);$text=trim((string)($j['choices'][0]['message']['content']??''));$in=$j['usage']['prompt_tokens']??null;$out=$j['usage']['completion_tokens']??null;}
    if($text==='')throw new RuntimeException('AI provider returned no text.');return ['text'=>$text,'input_tokens'=>$in,'output_tokens'=>$out,'model'=>$m];
}
function ai_run(PDO $pdo,array $config,?array $user,string $initiatedBy,string $taskType,int $modelId,string $system,string $prompt,array $refs=[],?string $scopeType=null,?string $scopePublicId=null): array {
    $public=ulid_like();$q=$pdo->prepare("INSERT INTO ai_runs(public_id,user_id,initiated_by,task_type,model_id,prompt_version,scope_type,scope_public_id,input_refs_json,status) VALUES(?,?,?,?,?,'v1',?,?,?,'processing')");$q->execute([$public,$user['id']??null,$initiatedBy,$taskType,$modelId,$scopeType,$scopePublicId,json_encode($refs,JSON_UNESCAPED_SLASHES)]);$id=(int)$pdo->lastInsertId();
    try{$r=ai_generate($pdo,$config,$modelId,$system,$prompt);$pdo->prepare("UPDATE ai_runs SET output_text=?,status='completed',input_tokens=?,output_tokens=?,completed_at=NOW() WHERE id=?")->execute([$r['text'],$r['input_tokens'],$r['output_tokens'],$id]);$lineage=function_exists('data_response_try_record')?data_response_try_record($pdo,$id,$public,$user,(string)$r['text'],$refs):null;return ['public_id'=>$public,'text'=>$r['text'],'model'=>$r['model'],'lineage'=>$lineage];}catch(Throwable $e){$pdo->prepare("UPDATE ai_runs SET status='failed',error_text=?,completed_at=NOW() WHERE id=?")->execute([substr($e->getMessage(),0,1000),$id]);throw $e;}
}
function ai_queue_job(PDO $pdo,?int $userId,string $taskType,?int $modelId,string $objectType,string $objectPublicId,array $input=[],int $priority=5): string {
    $public=ulid_like();$q=$pdo->prepare('INSERT INTO ai_jobs(public_id,requested_by_user_id,task_type,model_id,object_type,object_public_id,input_json,priority) VALUES(?,?,?,?,?,?,?,?)');$q->execute([$public,$userId,$taskType,$modelId?:null,$objectType,$objectPublicId,json_encode($input,JSON_UNESCAPED_SLASHES),max(1,min(9,$priority))]);return $public;
}
function ai_research_context(PDO $pdo,int $projectId): array {
    $refs=[];$chunks=[];$q=$pdo->prepare('SELECT s.public_id,s.title,s.canonical_url,sv.extracted_text FROM project_sources ps JOIN sources s ON s.id=ps.source_id LEFT JOIN source_versions sv ON sv.id=s.current_version_id WHERE ps.project_id=? ORDER BY ps.created_at DESC LIMIT 20');$q->execute([$projectId]);foreach($q->fetchAll() as $r){$refs[]=['type'=>'source','id'=>$r['public_id']];$chunks[]='[SOURCE '.$r['public_id']."]\n".($r['title']?:$r['canonical_url'])."\n".mb_substr((string)$r['extracted_text'],0,6000);}
    $q=$pdo->prepare("SELECT a.public_id,a.text_commentary,c.capture_type,c.selected_text,c.start_seconds,c.end_seconds,c.media_provider,c.provider_media_id,c.media_title,c.media_author,COALESCE(at.edited_text,at.raw_text) transcript_text FROM project_annotations pa JOIN annotations a ON a.id=pa.annotation_id JOIN captures c ON c.id=a.capture_id LEFT JOIN annotation_transcripts at ON at.annotation_id=a.id WHERE pa.project_id=? AND a.status='published' ORDER BY pa.created_at DESC LIMIT 30");$q->execute([$projectId]);foreach($q->fetchAll() as $r){$refs[]=['type'=>'annotation','id'=>$r['public_id']];$media='';if(in_array($r['capture_type'],['video_clip','audio_clip'],true)){$media="\nMedia: ".($r['media_provider']?:'web').($r['media_title']?' · '.$r['media_title']:'').($r['media_author']?' · '.$r['media_author']:'').($r['provider_media_id']?' · ID '.$r['provider_media_id']:'')." · clip ".(string)$r['start_seconds']."-".(string)$r['end_seconds']." seconds";}$chunks[]='[ANNOTATION '.$r['public_id']."]\nCommentary: ".mb_substr((string)$r['text_commentary'],0,2500)."\nCaptured: ".mb_substr((string)$r['selected_text'],0,3000).$media.($r['transcript_text']?"\nTranscript: ".mb_substr((string)$r['transcript_text'],0,3000):'');}
    return ['refs'=>$refs,'text'=>implode("\n\n",$chunks)];
}
