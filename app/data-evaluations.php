<?php
declare(strict_types=1);
require_once __DIR__.'/ai.php';

/**
 * Phase 39 — Dataset Evaluation & Benchmark Harness
 *
 * Formal evaluations run only against frozen, currently usable Phase 38 datasets
 * whose purpose is "evaluation". Retrieval metrics are deterministic. Model metrics
 * are transparent heuristics and never replace human review.
 */

function data_evaluation_ready(PDO $pdo): bool {
    try{return data_dataset_ready($pdo)&&installer_table_exists($pdo,'data_evaluation_suites')&&installer_table_exists($pdo,'data_evaluation_runs');}
    catch(Throwable $e){return false;}
}
function data_evaluation_require_admin(array $viewer): void {
    if(($viewer['role']??'')!=='admin')throw new RuntimeException('Administrator access is required for Evaluation Harness operations.');
}
function data_evaluation_types(): array {
    return [
        'retrieval'=>['label'=>'Retrieval benchmark','description'=>'Deterministic lexical retrieval over frozen dataset snapshots.'],
        'model'=>['label'=>'Model response benchmark','description'=>'Configured inference model evaluated against frozen context; automated metrics remain advisory.'],
    ];
}
function data_evaluation_default_prompt(): string {
    return "Answer the benchmark question using only the supplied Annotated dataset context. Cite supporting dataset items as [[CORPUS_PUBLIC_ID]]. If the context is insufficient, say so. Do not invent citations.";
}
function data_evaluation_event(PDO $pdo,int $suiteId,?int $runId,?int $actorUserId,string $type,array $details=[]): void {
    $pdo->prepare('INSERT INTO data_evaluation_events(suite_id,run_id,actor_user_id,event_type,details_json) VALUES(?,?,?,?,?)')->execute([$suiteId,$runId,$actorUserId,$type,$details?data_attribution_encode($details):null]);
}
function data_evaluation_config(array $input): array {
    $type=strtolower(trim((string)($input['benchmark_type']??'retrieval')));if(!isset(data_evaluation_types()[$type]))throw new InvalidArgumentException('Invalid benchmark type.');
    $topK=max(1,min(20,(int)($input['top_k']??5)));
    $modelId=$type==='model'?max(0,(int)($input['model_id']??0)):0;
    if($type==='model'&&!$modelId)throw new InvalidArgumentException('Model benchmarks require an enabled model.');
    $prompt=trim((string)($input['prompt_template']??''));if($type==='model'&&$prompt==='')$prompt=data_evaluation_default_prompt();
    return ['benchmark_type'=>$type,'top_k'=>$topK,'model_id'=>$modelId?:null,'prompt_template'=>$type==='model'?$prompt:null,'metric_version'=>'phase39-v1'];
}
function data_evaluation_dataset_assert(PDO $pdo,int $datasetId): array {
    $q=$pdo->prepare('SELECT * FROM data_datasets WHERE id=? LIMIT 1');$q->execute([$datasetId]);$d=$q->fetch();if(!$d)throw new RuntimeException('Evaluation dataset not found.');
    if($d['status']!=='frozen')throw new RuntimeException('Evaluation Harness requires a frozen dataset.');
    if($d['purpose']!=='evaluation')throw new RuntimeException('Formal benchmarks require a dataset frozen specifically for evaluation use.');
    $status=data_dataset_current_use_status($pdo,(string)$d['public_id']);if(!$status['usable'])throw new RuntimeException('Evaluation dataset is currently blocked by rights, consent, content, provenance, or manifest integrity.');
    return $d;
}
function data_evaluation_suite_create(PDO $pdo,array $viewer,array $input): array {
    data_evaluation_require_admin($viewer);if(!data_evaluation_ready($pdo))throw new RuntimeException('Evaluation Harness requires the Phase 39 database upgrade.');
    $datasetId=max(0,(int)($input['dataset_id']??0));$dataset=data_evaluation_dataset_assert($pdo,$datasetId);
    $name=mb_substr(trim((string)($input['name']??'')),0,180);if($name==='')throw new InvalidArgumentException('Suite name is required.');
    $description=mb_substr(trim((string)($input['description']??'')),0,5000)?:null;$config=data_evaluation_config($input);$configJson=data_attribution_encode($config);$configHash=hash('sha256',$configJson);$public=ulid_like();
    $pdo->prepare("INSERT INTO data_evaluation_suites(public_id,dataset_id,name,benchmark_type,status,description,top_k,model_id,prompt_template,config_json,config_hash,created_by_user_id) VALUES(?,?,?,?,'draft',?,?,?,?,?,?,?)")
        ->execute([$public,$datasetId,$name,$config['benchmark_type'],$description,$config['top_k'],$config['model_id'],$config['prompt_template'],$configJson,$configHash,$viewer['id']]);$id=(int)$pdo->lastInsertId();
    data_evaluation_event($pdo,$id,null,(int)$viewer['id'],'suite_created',['dataset_manifest_hash'=>$dataset['manifest_hash'],'config_hash'=>$configHash]);
    return data_evaluation_suite_get($pdo,$public)??[];
}
function data_evaluation_suite_get(PDO $pdo,string $publicId): ?array {
    if(!data_evaluation_ready($pdo))return null;$q=$pdo->prepare('SELECT s.*,d.public_id dataset_public_id,d.name dataset_name,d.version_number dataset_version,d.manifest_hash dataset_manifest_hash,m.display_name model_name,p.label model_provider FROM data_evaluation_suites s JOIN data_datasets d ON d.id=s.dataset_id LEFT JOIN ai_models m ON m.id=s.model_id LEFT JOIN ai_providers p ON p.id=m.provider_id WHERE s.public_id=? LIMIT 1');$q->execute([trim($publicId)]);$r=$q->fetch();if(!$r)return null;$r['config']=json_decode((string)$r['config_json'],true)?:[];return $r;
}
function data_evaluation_suite_list(PDO $pdo,int $limit=100): array {
    if(!data_evaluation_ready($pdo))return [];$limit=max(1,min(250,$limit));$q=$pdo->query("SELECT s.*,d.public_id dataset_public_id,d.name dataset_name,d.version_number dataset_version,m.display_name model_name FROM data_evaluation_suites s JOIN data_datasets d ON d.id=s.dataset_id LEFT JOIN ai_models m ON m.id=s.model_id ORDER BY s.id DESC LIMIT $limit");return $q->fetchAll();
}
function data_evaluation_suite_update(PDO $pdo,array $viewer,string $publicId,array $input): array {
    data_evaluation_require_admin($viewer);$s=data_evaluation_suite_get($pdo,$publicId);if(!$s)throw new RuntimeException('Evaluation suite not found.');if($s['status']!=='draft')throw new RuntimeException('Active or retired evaluation suites are immutable.');
    $name=mb_substr(trim((string)($input['name']??$s['name'])),0,180);if($name==='')throw new InvalidArgumentException('Suite name is required.');$description=mb_substr(trim((string)($input['description']??$s['description']??'')),0,5000)?:null;
    $config=data_evaluation_config(array_merge((array)$s['config'],$input));$configJson=data_attribution_encode($config);$configHash=hash('sha256',$configJson);
    $pdo->prepare('UPDATE data_evaluation_suites SET name=?,benchmark_type=?,description=?,top_k=?,model_id=?,prompt_template=?,config_json=?,config_hash=?,updated_at=NOW() WHERE id=?')
        ->execute([$name,$config['benchmark_type'],$description,$config['top_k'],$config['model_id'],$config['prompt_template'],$configJson,$configHash,$s['id']]);
    data_evaluation_event($pdo,(int)$s['id'],null,(int)$viewer['id'],'suite_updated',['config_hash'=>$configHash]);
    return data_evaluation_suite_get($pdo,$publicId)??[];
}
function data_evaluation_case_hash(array $case): string {
    return data_attribution_hash(['label'=>(string)$case['label'],'query_text'=>(string)$case['query_text'],'reference_answer'=>(string)($case['reference_answer']??''),'expected_dataset_item_id'=>(int)$case['expected_dataset_item_id'],'weight'=>(string)$case['weight'],'tags'=>$case['tags']??[]]);
}
function data_evaluation_case_add(PDO $pdo,array $viewer,string $suitePublicId,array $input): array {
    data_evaluation_require_admin($viewer);$s=data_evaluation_suite_get($pdo,$suitePublicId);if(!$s)throw new RuntimeException('Evaluation suite not found.');if($s['status']!=='draft')throw new RuntimeException('Cases can only be changed while the suite is draft.');
    $label=mb_substr(trim((string)($input['label']??'')),0,180);$query=mb_substr(trim((string)($input['query_text']??'')),0,5000);$reference=mb_substr(trim((string)($input['reference_answer']??'')),0,12000)?:null;$itemId=max(0,(int)($input['expected_dataset_item_id']??0));$weight=max(0.01,min(100.0,(float)($input['weight']??1)));
    if($label===''||$query===''||!$itemId)throw new InvalidArgumentException('Case label, query, and expected dataset item are required.');
    $q=$pdo->prepare('SELECT id FROM data_dataset_items WHERE id=? AND dataset_id=? LIMIT 1');$q->execute([$itemId,$s['dataset_id']]);if(!$q->fetchColumn())throw new RuntimeException('Expected dataset item does not belong to this suite dataset.');
    $tags=$input['tags']??[];if(is_string($tags))$tags=preg_split('/[\s,]+/',$tags,-1,PREG_SPLIT_NO_EMPTY)?:[];$tags=array_values(array_unique(array_filter(array_map(fn($v)=>mb_substr(strtolower(trim((string)$v)),0,64),(array)$tags))));
    $q=$pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM data_evaluation_cases WHERE suite_id=?');$q->execute([$s['id']]);$position=(int)$q->fetchColumn();$public=ulid_like();
    $case=['label'=>$label,'query_text'=>$query,'reference_answer'=>$reference,'expected_dataset_item_id'=>$itemId,'weight'=>$weight,'tags'=>$tags];$hash=data_evaluation_case_hash($case);
    $pdo->prepare('INSERT INTO data_evaluation_cases(public_id,suite_id,expected_dataset_item_id,label,query_text,reference_answer,weight,tags_json,case_hash,position,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$public,$s['id'],$itemId,$label,$query,$reference,$weight,$tags?data_attribution_encode($tags):null,$hash,$position,$viewer['id']]);
    data_evaluation_event($pdo,(int)$s['id'],null,(int)$viewer['id'],'case_added',['case_public_id'=>$public,'case_hash'=>$hash]);
    return data_evaluation_case_get($pdo,$public)??[];
}
function data_evaluation_case_get(PDO $pdo,string $publicId): ?array {
    $q=$pdo->prepare('SELECT c.*,ddi.corpus_public_id expected_corpus_public_id,ddi.source_object_type expected_source_type,ddi.source_object_public_id expected_source_public_id FROM data_evaluation_cases c JOIN data_dataset_items ddi ON ddi.id=c.expected_dataset_item_id WHERE c.public_id=? LIMIT 1');$q->execute([trim($publicId)]);return $q->fetch()?:null;
}
function data_evaluation_cases(PDO $pdo,int $suiteId): array {
    $q=$pdo->prepare('SELECT c.*,ddi.corpus_public_id expected_corpus_public_id,ddi.source_object_type expected_source_type,ddi.source_object_public_id expected_source_public_id FROM data_evaluation_cases c JOIN data_dataset_items ddi ON ddi.id=c.expected_dataset_item_id WHERE c.suite_id=? ORDER BY c.position,c.id');$q->execute([$suiteId]);return $q->fetchAll();
}
function data_evaluation_case_delete(PDO $pdo,array $viewer,string $suitePublicId,string $casePublicId): bool {
    data_evaluation_require_admin($viewer);$s=data_evaluation_suite_get($pdo,$suitePublicId);if(!$s||$s['status']!=='draft')throw new RuntimeException('Cases can only be changed while the suite is draft.');$c=data_evaluation_case_get($pdo,$casePublicId);if(!$c||(int)$c['suite_id']!==(int)$s['id'])return false;
    $pdo->prepare('DELETE FROM data_evaluation_cases WHERE id=?')->execute([$c['id']]);data_evaluation_event($pdo,(int)$s['id'],null,(int)$viewer['id'],'case_deleted',['case_public_id'=>$casePublicId]);return true;
}
function data_evaluation_cases_hash(PDO $pdo,int $suiteId): string {
    $cases=data_evaluation_cases($pdo,$suiteId);return data_attribution_hash(array_map(fn($c)=>['position'=>(int)$c['position'],'public_id'=>$c['public_id'],'case_hash'=>$c['case_hash']],$cases));
}
function data_evaluation_suite_activate(PDO $pdo,array $viewer,string $publicId): array {
    data_evaluation_require_admin($viewer);$s=data_evaluation_suite_get($pdo,$publicId);if(!$s)throw new RuntimeException('Evaluation suite not found.');if($s['status']!=='draft')throw new RuntimeException('Only draft suites can be activated.');data_evaluation_dataset_assert($pdo,(int)$s['dataset_id']);
    $cases=data_evaluation_cases($pdo,(int)$s['id']);if(!$cases)throw new RuntimeException('Add at least one benchmark case before activating the suite.');
    if($s['benchmark_type']==='model')ai_model_record($pdo,(int)$s['model_id']);
    $pdo->prepare("UPDATE data_evaluation_suites SET status='active',updated_at=NOW() WHERE id=?")->execute([$s['id']]);data_evaluation_event($pdo,(int)$s['id'],null,(int)$viewer['id'],'suite_activated',['cases_hash'=>data_evaluation_cases_hash($pdo,(int)$s['id']),'case_count'=>count($cases)]);
    return data_evaluation_suite_get($pdo,$publicId)??[];
}
function data_evaluation_suite_retire(PDO $pdo,array $viewer,string $publicId): array {
    data_evaluation_require_admin($viewer);$s=data_evaluation_suite_get($pdo,$publicId);if(!$s)throw new RuntimeException('Evaluation suite not found.');if($s['status']==='retired')return $s;if($s['status']!=='active')throw new RuntimeException('Only active suites can be retired.');
    $pdo->prepare("UPDATE data_evaluation_suites SET status='retired',updated_at=NOW() WHERE id=?")->execute([$s['id']]);data_evaluation_event($pdo,(int)$s['id'],null,(int)$viewer['id'],'suite_retired',[]);return data_evaluation_suite_get($pdo,$publicId)??[];
}
function data_evaluation_tokens(string $text): array {
    $text=mb_strtolower($text);$parts=preg_split('/[^\p{L}\p{N}]+/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[];$stop=['the','a','an','and','or','of','to','in','on','for','is','are','was','were','be','by','with','from','that','this','it','as','at'];
    $out=[];foreach($parts as $p){if(mb_strlen($p)<2||in_array($p,$stop,true))continue;$out[$p]=true;}return array_keys($out);
}
function data_evaluation_overlap(string $a,string $b): array {
    $aa=data_evaluation_tokens($a);$bb=data_evaluation_tokens($b);if(!$aa)return ['coverage'=>0.0,'precision'=>0.0,'f1'=>0.0,'matched'=>0,'a_terms'=>0,'b_terms'=>count($bb)];
    $set=array_fill_keys($bb,true);$matched=0;foreach($aa as $t)if(isset($set[$t]))$matched++;$coverage=$matched/max(1,count($aa));$precision=$matched/max(1,count($bb));$f1=($coverage+$precision)>0?2*$coverage*$precision/($coverage+$precision):0.0;
    return ['coverage'=>$coverage,'precision'=>$precision,'f1'=>$f1,'matched'=>$matched,'a_terms'=>count($aa),'b_terms'=>count($bb)];
}
function data_evaluation_dataset_rows(PDO $pdo,int $datasetId): array {
    $q=$pdo->prepare('SELECT id,position,corpus_public_id,source_object_type,source_object_public_id,source_object_version,corpus_type,normalized_text_snapshot,content_hash,provenance_hash FROM data_dataset_items WHERE dataset_id=? ORDER BY position,id');$q->execute([$datasetId]);return $q->fetchAll();
}
function data_evaluation_rank_rows(array $rows,string $query,int $topK): array {
    $queryNorm=mb_strtolower(trim($query));$scored=[];foreach($rows as $row){$ov=data_evaluation_overlap($query,(string)$row['normalized_text_snapshot']);$phrase=$queryNorm!==''&&str_contains(mb_strtolower((string)$row['normalized_text_snapshot']),$queryNorm)?0.15:0.0;$score=min(1.0,$ov['coverage']+$phrase);$scored[]=['dataset_item_id'=>(int)$row['id'],'position'=>(int)$row['position'],'corpus_public_id'=>$row['corpus_public_id'],'source_object_type'=>$row['source_object_type'],'source_object_public_id'=>$row['source_object_public_id'],'score'=>$score,'query_coverage'=>$ov['coverage']];}
    usort($scored,fn($a,$b)=>$a['score']===$b['score']?($a['position']<=>$b['position']):($a['score']<$b['score']?1:-1));$rank=1;foreach($scored as &$r)$r['rank']=$rank++;unset($r);return ['all'=>$scored,'top'=>array_slice($scored,0,max(1,$topK))];
}
function data_evaluation_parse_citations(string $text): array {
    preg_match_all('/\[\[([^\]\r\n]{1,80})\]\]/u',$text,$m);return array_values(array_unique(array_map('trim',$m[1]??[])));
}
function data_evaluation_model_prompt(array $suite,array $case,array $ranked,array $rowsById): array {
    $chunks=[];$refs=[];$chars=0;foreach($ranked['top'] as $hit){$row=$rowsById[$hit['dataset_item_id']]??null;if(!$row)continue;$text=(string)$row['normalized_text_snapshot'];$available=max(0,30000-$chars);if($available<=0)break;$text=mb_substr($text,0,min(8000,$available));$chars+=mb_strlen($text);$chunks[]='[ITEM '.$row['corpus_public_id']."]\n".$text;$refs[]=['type'=>'dataset_item','id'=>$row['corpus_public_id']];}
    $prompt="QUESTION:\n".$case['query_text']."\n\nDATASET CONTEXT:\n".implode("\n\n",$chunks)."\n\nReturn a concise answer with citations in the form [[CORPUS_PUBLIC_ID]].";
    return ['system'=>(string)($suite['prompt_template']?:data_evaluation_default_prompt()),'prompt'=>$prompt,'refs'=>$refs,'context_text'=>implode("\n",$chunks)];
}
function data_evaluation_model_snapshot(PDO $pdo,?int $modelId): ?array {
    if(!$modelId)return null;$m=ai_model_record($pdo,$modelId);return ['public_id'=>$m['public_id'],'display_name'=>$m['display_name'],'model_name'=>$m['model_name'],'provider_label'=>$m['provider_label'],'provider_type'=>$m['provider_type'],'max_output_tokens'=>(int)$m['max_output_tokens']];
}
function data_evaluation_run_queue(PDO $pdo,array $viewer,string $suitePublicId): array {
    data_evaluation_require_admin($viewer);$s=data_evaluation_suite_get($pdo,$suitePublicId);if(!$s)throw new RuntimeException('Evaluation suite not found.');if($s['status']!=='active')throw new RuntimeException('Only active suites can be evaluated.');
    $dataset=data_evaluation_dataset_assert($pdo,(int)$s['dataset_id']);$cases=data_evaluation_cases($pdo,(int)$s['id']);if(!$cases)throw new RuntimeException('Evaluation suite has no cases.');$casesHash=data_evaluation_cases_hash($pdo,(int)$s['id']);$modelSnapshot=$s['benchmark_type']==='model'?data_evaluation_model_snapshot($pdo,(int)$s['model_id']):null;$public=ulid_like();
    $pdo->prepare("INSERT INTO data_evaluation_runs(public_id,suite_id,dataset_id,benchmark_type,model_id,model_snapshot_json,dataset_manifest_hash,suite_config_hash,cases_hash,status,runner,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,'queued','worker',?)")
        ->execute([$public,$s['id'],$s['dataset_id'],$s['benchmark_type'],$s['model_id'],$modelSnapshot?data_attribution_encode($modelSnapshot):null,$dataset['manifest_hash'],$s['config_hash'],$casesHash,$viewer['id']]);$runId=(int)$pdo->lastInsertId();
    data_evaluation_event($pdo,(int)$s['id'],$runId,(int)$viewer['id'],'run_queued',['run_public_id'=>$public,'dataset_manifest_hash'=>$dataset['manifest_hash'],'cases_hash'=>$casesHash,'config_hash'=>$s['config_hash']]);
    return data_evaluation_run_get($pdo,$public)??[];
}
function data_evaluation_run_get(PDO $pdo,string $publicId): ?array {
    $q=$pdo->prepare('SELECT r.*,s.public_id suite_public_id,s.name suite_name,d.public_id dataset_public_id,d.name dataset_name,m.display_name model_name FROM data_evaluation_runs r JOIN data_evaluation_suites s ON s.id=r.suite_id JOIN data_datasets d ON d.id=r.dataset_id LEFT JOIN ai_models m ON m.id=r.model_id WHERE r.public_id=? LIMIT 1');$q->execute([trim($publicId)]);$r=$q->fetch();if(!$r)return null;$r['summary']=$r['result_summary_json']?json_decode((string)$r['result_summary_json'],true):null;return $r;
}
function data_evaluation_runs(PDO $pdo,int $suiteId,int $limit=50): array {
    $limit=max(1,min(200,$limit));$q=$pdo->prepare("SELECT r.*,m.display_name model_name FROM data_evaluation_runs r LEFT JOIN ai_models m ON m.id=r.model_id WHERE r.suite_id=? ORDER BY r.id DESC LIMIT $limit");$q->execute([$suiteId]);$rows=$q->fetchAll();foreach($rows as &$r)$r['summary']=$r['result_summary_json']?json_decode((string)$r['result_summary_json'],true):null;unset($r);return $rows;
}
function data_evaluation_result_insert(PDO $pdo,int $runId,array $case,array $ranked,?string $response,array $metrics,bool $passed,array $citations=[]): void {
    $expectedRank=null;foreach($ranked['all'] as $r)if((int)$r['dataset_item_id']===(int)$case['expected_dataset_item_id']){$expectedRank=(int)$r['rank'];break;}$metricsJson=data_attribution_encode($metrics);$metricsHash=hash('sha256',$metricsJson);
    $pdo->prepare('INSERT INTO data_evaluation_results(run_id,case_id,rank_of_expected,retrieved_json,response_text,response_hash,citations_json,metrics_json,metrics_hash,passed) VALUES(?,?,?,?,?,?,?,?,?,?)')
        ->execute([$runId,$case['id'],$expectedRank,data_attribution_encode($ranked['top']),$response,$response!==null?hash('sha256',$response):null,$citations?data_attribution_encode($citations):null,$metricsJson,$metricsHash,$passed?1:0]);
}
function data_evaluation_summary(PDO $pdo,int $runId,string $type): array {
    $q=$pdo->prepare('SELECT r.*,c.weight FROM data_evaluation_results r JOIN data_evaluation_cases c ON c.id=r.case_id WHERE r.run_id=? ORDER BY c.position');$q->execute([$runId]);$rows=$q->fetchAll();$weightTotal=0.0;$passWeight=0.0;$sums=[];$counts=[];
    foreach($rows as $r){$w=(float)$r['weight'];$weightTotal+=$w;if((int)$r['passed'])$passWeight+=$w;$m=json_decode((string)$r['metrics_json'],true)?:[];foreach($m as $k=>$v)if(is_numeric($v)){$sums[$k]=($sums[$k]??0.0)+(float)$v*$w;$counts[$k]=($counts[$k]??0.0)+$w;}}
    $avg=[];foreach($sums as $k=>$v)$avg[$k]=$counts[$k]>0?$v/$counts[$k]:0.0;
    return ['metric_version'=>'phase39-v1','benchmark_type'=>$type,'case_count'=>count($rows),'weight_total'=>$weightTotal,'automated_pass_rate'=>$weightTotal>0?$passWeight/$weightTotal:0.0,'metrics'=>$avg];
}
function data_evaluation_execute_run(PDO $pdo,array $config,int $runId): array {
    if(!data_evaluation_ready($pdo))throw new RuntimeException('Evaluation Harness is unavailable.');
    $pdo->beginTransaction();try{$q=$pdo->prepare("SELECT * FROM data_evaluation_runs WHERE id=? FOR UPDATE");$q->execute([$runId]);$run=$q->fetch();if(!$run)throw new RuntimeException('Evaluation run not found.');if(!in_array($run['status'],['queued','processing'],true))throw new RuntimeException('Evaluation run is not runnable.');if($run['status']==='queued')$pdo->prepare("UPDATE data_evaluation_runs SET status='processing',started_at=NOW(),error_text=NULL WHERE id=?")->execute([$runId]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    try{
        $q=$pdo->prepare('SELECT * FROM data_evaluation_suites WHERE id=?');$q->execute([$run['suite_id']]);$suite=$q->fetch();if(!$suite)throw new RuntimeException('Evaluation suite disappeared.');
        $dataset=data_evaluation_dataset_assert($pdo,(int)$run['dataset_id']);if(!hash_equals((string)$run['dataset_manifest_hash'],(string)$dataset['manifest_hash']))throw new RuntimeException('Dataset manifest changed after the evaluation run was queued.');
        if(!hash_equals((string)$run['suite_config_hash'],(string)$suite['config_hash']))throw new RuntimeException('Suite configuration changed after the evaluation run was queued.');
        $casesHash=data_evaluation_cases_hash($pdo,(int)$suite['id']);if(!hash_equals((string)$run['cases_hash'],$casesHash))throw new RuntimeException('Benchmark cases changed after the evaluation run was queued.');
        $cases=data_evaluation_cases($pdo,(int)$suite['id']);$rows=data_evaluation_dataset_rows($pdo,(int)$run['dataset_id']);$rowsById=[];foreach($rows as $row)$rowsById[(int)$row['id']]=$row;$topK=(int)$suite['top_k'];
        $pdo->prepare('DELETE FROM data_evaluation_results WHERE run_id=?')->execute([$runId]);
        foreach($cases as $case){
            $ranked=data_evaluation_rank_rows($rows,(string)$case['query_text'],$topK);$expectedRank=null;foreach($ranked['all'] as $hit)if((int)$hit['dataset_item_id']===(int)$case['expected_dataset_item_id']){$expectedRank=(int)$hit['rank'];break;}
            $base=['expected_rank'=>$expectedRank??0,'hit_at_1'=>$expectedRank===1?1:0,'hit_at_3'=>$expectedRank!==null&&$expectedRank<=3?1:0,'hit_at_5'=>$expectedRank!==null&&$expectedRank<=5?1:0,'mrr'=>$expectedRank?1/$expectedRank:0.0,'top_score'=>(float)($ranked['top'][0]['score']??0)];
            if($run['benchmark_type']==='retrieval'){
                data_evaluation_result_insert($pdo,$runId,$case,$ranked,null,$base,$expectedRank!==null&&$expectedRank<=$topK);continue;
            }
            $mp=data_evaluation_model_prompt($suite,$case,$ranked,$rowsById);$model=ai_generate($pdo,$config,(int)$run['model_id'],$mp['system'],$mp['prompt'],2048);$response=(string)$model['text'];$citations=data_evaluation_parse_citations($response);$expectedCorpus=(string)$case['expected_corpus_public_id'];$citationHit=in_array($expectedCorpus,$citations,true)?1:0;$ground=data_evaluation_overlap($response,$mp['context_text']);$reference=trim((string)($case['reference_answer']??''));$ref=$reference!==''?data_evaluation_overlap($reference,$response):['f1'=>0.0,'coverage'=>0.0];$metrics=array_merge($base,['expected_citation'=>$citationHit,'grounded_token_ratio'=>$ground['coverage'],'reference_token_f1'=>(float)$ref['f1']]);$pass=$citationHit===1&&$ground['coverage']>=0.35&&($reference===''||$ref['f1']>=0.20);
            data_evaluation_result_insert($pdo,$runId,$case,$ranked,$response,$metrics,$pass,$citations);
        }
        $summary=data_evaluation_summary($pdo,$runId,(string)$run['benchmark_type']);$summaryJson=data_attribution_encode($summary);$summaryHash=hash('sha256',$summaryJson);$pdo->prepare("UPDATE data_evaluation_runs SET status='completed',result_summary_json=?,result_summary_hash=?,completed_at=NOW() WHERE id=?")->execute([$summaryJson,$summaryHash,$runId]);data_evaluation_event($pdo,(int)$run['suite_id'],$runId,null,'run_completed',['summary_hash'=>$summaryHash,'case_count'=>$summary['case_count']]);return data_evaluation_run_get($pdo,(string)$run['public_id'])??[];
    }catch(Throwable $e){$pdo->prepare("UPDATE data_evaluation_runs SET status='failed',error_text=?,completed_at=NOW() WHERE id=?")->execute([mb_substr($e->getMessage(),0,1000),$runId]);data_evaluation_event($pdo,(int)$run['suite_id'],$runId,null,'run_failed',['error'=>mb_substr($e->getMessage(),0,500)]);throw $e;}
}
function data_evaluation_process_next(PDO $pdo,array $config): ?array {
    $pdo->beginTransaction();try{$q=$pdo->query("SELECT id FROM data_evaluation_runs WHERE status='queued' ORDER BY created_at,id LIMIT 1 FOR UPDATE");$id=(int)($q->fetchColumn()?:0);if(!$id){$pdo->commit();return null;}$pdo->prepare("UPDATE data_evaluation_runs SET status='processing',started_at=NOW(),error_text=NULL WHERE id=? AND status='queued'")->execute([$id]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}return data_evaluation_execute_run($pdo,$config,$id);
}
function data_evaluation_results(PDO $pdo,int $runId): array {
    $q=$pdo->prepare('SELECT r.*,c.public_id case_public_id,c.label,c.query_text,c.reference_answer,c.expected_dataset_item_id,ddi.corpus_public_id expected_corpus_public_id FROM data_evaluation_results r JOIN data_evaluation_cases c ON c.id=r.case_id JOIN data_dataset_items ddi ON ddi.id=c.expected_dataset_item_id WHERE r.run_id=? ORDER BY c.position,c.id');$q->execute([$runId]);$rows=$q->fetchAll();foreach($rows as &$r){$r['metrics']=json_decode((string)$r['metrics_json'],true)?:[];$r['retrieved']=json_decode((string)($r['retrieved_json']??''),true)?:[];$r['citations']=json_decode((string)($r['citations_json']??''),true)?:[];}unset($r);return $rows;
}
function data_evaluation_review_save(PDO $pdo,array $viewer,int $resultId,array $input): array {
    data_evaluation_require_admin($viewer);$decision=(string)($input['decision']??'needs_work');if(!in_array($decision,['pass','fail','needs_work'],true))throw new InvalidArgumentException('Invalid review decision.');
    $score=function(string $k)use($input){if(($input[$k]??'')==='')return null;$v=(int)$input[$k];if($v<1||$v>5)throw new InvalidArgumentException('Human review scores must be between 1 and 5.');return $v;};$rel=$score('relevance_score');$ground=$score('groundedness_score');$acc=$score('accuracy_score');$note=mb_substr(trim((string)($input['note']??'')),0,5000)?:null;
    $q=$pdo->prepare('SELECT r.id,r.run_id,run.suite_id FROM data_evaluation_results r JOIN data_evaluation_runs run ON run.id=r.run_id WHERE r.id=?');$q->execute([$resultId]);$row=$q->fetch();if(!$row)throw new RuntimeException('Evaluation result not found.');$public=ulid_like();
    $pdo->prepare('INSERT INTO data_evaluation_reviews(public_id,result_id,reviewer_user_id,decision,relevance_score,groundedness_score,accuracy_score,note) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE decision=VALUES(decision),relevance_score=VALUES(relevance_score),groundedness_score=VALUES(groundedness_score),accuracy_score=VALUES(accuracy_score),note=VALUES(note),updated_at=NOW()')
        ->execute([$public,$resultId,$viewer['id'],$decision,$rel,$ground,$acc,$note]);data_evaluation_event($pdo,(int)$row['suite_id'],(int)$row['run_id'],(int)$viewer['id'],'human_review_saved',['result_id'=>$resultId,'decision'=>$decision]);
    $q=$pdo->prepare('SELECT * FROM data_evaluation_reviews WHERE result_id=? AND reviewer_user_id=?');$q->execute([$resultId,$viewer['id']]);return $q->fetch()?:[];
}
function data_evaluation_reviews_for_run(PDO $pdo,int $runId): array {
    $q=$pdo->prepare('SELECT rv.*,r.case_id,u.display_name reviewer_name FROM data_evaluation_reviews rv JOIN data_evaluation_results r ON r.id=rv.result_id JOIN users u ON u.id=rv.reviewer_user_id WHERE r.run_id=? ORDER BY rv.updated_at DESC');$q->execute([$runId]);return $q->fetchAll();
}
function data_evaluation_human_summary(PDO $pdo,int $runId): array {
    $q=$pdo->prepare("SELECT COUNT(*) reviews,SUM(decision='pass') passed,SUM(decision='fail') failed,SUM(decision='needs_work') needs_work,AVG(relevance_score) relevance,AVG(groundedness_score) groundedness,AVG(accuracy_score) accuracy FROM data_evaluation_reviews rv JOIN data_evaluation_results r ON r.id=rv.result_id WHERE r.run_id=?");$q->execute([$runId]);$r=$q->fetch()?:[];return ['reviews'=>(int)($r['reviews']??0),'pass'=>(int)($r['passed']??0),'fail'=>(int)($r['failed']??0),'needs_work'=>(int)($r['needs_work']??0),'avg_relevance'=>$r['relevance']!==null?(float)$r['relevance']:null,'avg_groundedness'=>$r['groundedness']!==null?(float)$r['groundedness']:null,'avg_accuracy'=>$r['accuracy']!==null?(float)$r['accuracy']:null];
}
function data_evaluation_set_baseline(PDO $pdo,array $viewer,string $suitePublicId,string $runPublicId): array {
    data_evaluation_require_admin($viewer);$s=data_evaluation_suite_get($pdo,$suitePublicId);$r=data_evaluation_run_get($pdo,$runPublicId);if(!$s||!$r||(int)$r['suite_id']!==(int)$s['id'])throw new RuntimeException('Run does not belong to this suite.');if($r['status']!=='completed')throw new RuntimeException('Only completed runs can become baselines.');
    if(!hash_equals((string)$r['dataset_manifest_hash'],(string)$s['dataset_manifest_hash']))throw new RuntimeException('Baseline run dataset manifest does not match the suite dataset.');
    $pdo->prepare('UPDATE data_evaluation_suites SET baseline_run_id=?,updated_at=NOW() WHERE id=?')->execute([$r['id'],$s['id']]);data_evaluation_event($pdo,(int)$s['id'],(int)$r['id'],(int)$viewer['id'],'baseline_set',['run_public_id'=>$runPublicId]);return data_evaluation_suite_get($pdo,$suitePublicId)??[];
}
function data_evaluation_regression(PDO $pdo,array $suite,array $run): ?array {
    $baselineId=(int)($suite['baseline_run_id']??0);if(!$baselineId||$run['status']!=='completed'||(int)$run['id']===$baselineId)return null;$q=$pdo->prepare("SELECT * FROM data_evaluation_runs WHERE id=? AND status='completed'");$q->execute([$baselineId]);$b=$q->fetch();if(!$b)return null;$base=json_decode((string)$b['result_summary_json'],true)?:[];$cur=$run['summary']??json_decode((string)$run['result_summary_json'],true)?:[];
    $keys=$run['benchmark_type']==='retrieval'?['automated_pass_rate','hit_at_1','hit_at_3','hit_at_5','mrr']:['automated_pass_rate','expected_citation','grounded_token_ratio','reference_token_f1'];$deltas=[];$regressed=0;foreach($keys as $k){$bv=$k==='automated_pass_rate'?(float)($base[$k]??0):(float)($base['metrics'][$k]??0);$cv=$k==='automated_pass_rate'?(float)($cur[$k]??0):(float)($cur['metrics'][$k]??0);$delta=$cv-$bv;$deltas[$k]=['baseline'=>$bv,'current'=>$cv,'delta'=>$delta];if($delta<-0.02)$regressed++;}
    return ['baseline_run_id'=>$b['public_id'],'regressed_metrics'=>$regressed,'deltas'=>$deltas];
}
function data_evaluation_events(PDO $pdo,int $suiteId,int $limit=100): array {
    $limit=max(1,min(250,$limit));$q=$pdo->prepare("SELECT e.*,u.display_name actor_name,r.public_id run_public_id FROM data_evaluation_events e LEFT JOIN users u ON u.id=e.actor_user_id LEFT JOIN data_evaluation_runs r ON r.id=e.run_id WHERE e.suite_id=? ORDER BY e.id DESC LIMIT $limit");$q->execute([$suiteId]);return $q->fetchAll();
}
function data_evaluation_summary_global(PDO $pdo): array {
    if(!data_evaluation_ready($pdo))return ['ready'=>false];$scalar=fn(string $sql)=>(int)$pdo->query($sql)->fetchColumn();return ['ready'=>true,'suites'=>$scalar('SELECT COUNT(*) FROM data_evaluation_suites'),'active_suites'=>$scalar("SELECT COUNT(*) FROM data_evaluation_suites WHERE status='active'"),'queued_runs'=>$scalar("SELECT COUNT(*) FROM data_evaluation_runs WHERE status='queued'"),'completed_runs'=>$scalar("SELECT COUNT(*) FROM data_evaluation_runs WHERE status='completed'"),'failed_runs'=>$scalar("SELECT COUNT(*) FROM data_evaluation_runs WHERE status='failed'"),'human_reviews'=>$scalar('SELECT COUNT(*) FROM data_evaluation_reviews')];
}
