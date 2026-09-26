<?php
declare(strict_types=1);

/**
 * Phase 68 — Research Agent Report Studio
 *
 * Report Definition -> Report Run -> optional Research Document.
 * Everything is scoped to one Research Agent / Research Project.
 */

function research_report_studio_ready(PDO $pdo): bool {
    try{
        if(!research_system_reports_ready($pdo)||!installer_table_exists($pdo,'research_report_presets'))return false;
        $db=(string)($pdo->query('SELECT DATABASE()')->fetchColumn()?:'');if($db==='')return false;
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='research_system_reports' AND COLUMN_NAME IN ('rendered_html','knowledge_manifest_json')");
        $q->execute([$db]);return (int)$q->fetchColumn()===2;
    }catch(Throwable $e){return false;}
}

function research_report_studio_clean_ids($value,int $limit=100): array {
    $out=[];foreach(array_slice(is_array($value)?$value:[],0,$limit) as $id){$id=trim((string)$id);if($id!==''&&!in_array($id,$out,true))$out[]=$id;}return $out;
}

function research_report_studio_options(array $input=[]): array {
    $depth=strtolower(trim((string)($input['depth']??'standard')));if(!in_array($depth,['quick','standard','deep'],true))$depth='standard';
    $dateFrom=trim((string)($input['date_from']??''));if($dateFrom!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFrom))$dateFrom='';
    $dateTo=trim((string)($input['date_to']??''));if($dateTo!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateTo))$dateTo='';
    $focus=mb_substr(trim((string)($input['focus_query']??'')),0,500);
    return [
      'depth'=>$depth,'focus_query'=>$focus,'date_from'=>$dateFrom,'date_to'=>$dateTo,
      'source_ids'=>research_report_studio_clean_ids($input['source_ids']??[],100),
      'claim_ids'=>research_report_studio_clean_ids($input['claim_ids']??[],100),
      'finding_ids'=>research_report_studio_clean_ids($input['finding_ids']??[],100),
      'entity_ids'=>research_report_studio_clean_ids($input['entity_ids']??[],100),
      'folder_ids'=>research_report_studio_clean_ids($input['folder_ids']??[],30),
      'include_sections'=>research_report_studio_clean_ids($input['include_sections']??[],40),
    ];
}

function research_report_studio_limit(array $snapshot,int $quick,int $standard,int $deep): int {
    $depth=(string)($snapshot['studio']['depth']??'standard');
    return $depth==='quick'?$quick:($depth==='deep'?$deep:$standard);
}

function research_report_studio_row_matches_focus(array $row,string $query,array $fields): bool {
    if($query==='')return true;$hay=[];foreach($fields as $f)$hay[]=(string)($row[$f]??'');
    return mb_stripos(implode("\n",$hay),$query)!==false;
}

function research_report_studio_date_ok(array $row,string $from,string $to,array $fields): bool {
    if($from===''&&$to==='')return true;$raw='';
    foreach($fields as $f)if(!empty($row[$f])){$raw=(string)$row[$f];break;}
    if($raw==='')return true;$ts=strtotime($raw);if(!$ts)return true;
    if($from!==''&&$ts<strtotime($from.' 00:00:00'))return false;
    if($to!==''&&$ts>=strtotime($to.' +1 day'))return false;
    return true;
}

function research_report_studio_selected_claims(PDO $pdo,int $projectId,array $ids): array {
    if(!$ids)return [];$marks=implode(',',array_fill(0,count($ids),'?'));
    $q=$pdo->prepare("SELECT rc.public_id,rc.statement,rc.claim_type,rc.status,rc.updated_at,
      COUNT(ce.id) evidence_count,COUNT(DISTINCT ce.source_version_id) source_version_count,COUNT(DISTINCT sv.source_id) source_count,
      SUM(ce.relationship='supports') supports_count,SUM(ce.relationship='contradicts') contradicts_count,SUM(ce.relationship='primary') primary_count
      FROM research_claims rc LEFT JOIN claim_evidence ce ON ce.claim_id=rc.id LEFT JOIN source_versions sv ON sv.id=ce.source_version_id
      WHERE rc.project_id=? AND rc.public_id IN ($marks) GROUP BY rc.id ORDER BY rc.updated_at DESC,rc.id DESC");
    $q->execute(array_merge([$projectId],$ids));$rows=$q->fetchAll()?:[];foreach($rows as &$r)foreach(['evidence_count','source_version_count','source_count','supports_count','contradicts_count','primary_count'] as $k)$r[$k]=(int)($r[$k]??0);unset($r);return $rows;
}

function research_report_studio_selected_findings(PDO $pdo,int $projectId,array $ids): array {
    if(!$ids)return [];$marks=implode(',',array_fill(0,count($ids),'?'));
    $q=$pdo->prepare("SELECT rf.public_id,rf.title,rf.summary,rf.status,rf.updated_at,(SELECT COUNT(*) FROM finding_claims fc WHERE fc.finding_id=rf.id) claim_count
      FROM research_findings rf WHERE rf.project_id=? AND rf.status<>'archived' AND rf.public_id IN ($marks) ORDER BY rf.updated_at DESC,rf.id DESC");
    $q->execute(array_merge([$projectId],$ids));return $q->fetchAll()?:[];
}

function research_report_studio_selected_sources(PDO $pdo,int $projectId,array $ids): array {
    if(!$ids)return [];$marks=implode(',',array_fill(0,count($ids),'?'));
    $q=$pdo->prepare("SELECT s.public_id,s.title,s.domain,s.status,s.last_checked_at,sv.version_number,sv.captured_at,
      EXISTS(SELECT 1 FROM source_change_events sce WHERE sce.source_id=s.id AND sce.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) changed_30d
      FROM project_sources ps JOIN sources s ON s.id=ps.source_id LEFT JOIN source_versions sv ON sv.id=s.current_version_id
      WHERE ps.project_id=? AND s.public_id IN ($marks) ORDER BY COALESCE(sv.captured_at,s.last_checked_at) DESC,s.id DESC");
    $q->execute(array_merge([$projectId],$ids));return $q->fetchAll()?:[];
}

function research_report_studio_scope_snapshot(PDO $pdo,array $config,array $viewer,array $agent,array $snapshot,array $options): array {
    $o=research_report_studio_options($options);$focus=$o['focus_query'];$from=$o['date_from'];$to=$o['date_to'];$projectId=(int)$agent['project_id'];
    $explicit=(bool)($o['source_ids']||$o['claim_ids']||$o['finding_ids']||$o['entity_ids']||$o['folder_ids']);
    $scoped=$explicit||$focus!==''||$from!==''||$to!=='';
    $filterIds=function(array $rows,string $key,array $ids)use($from,$to): array{
        return array_values(array_filter($rows,function($r)use($key,$ids,$from,$to){
            if($ids&&!in_array((string)($r[$key]??''),$ids,true))return false;
            return research_report_studio_date_ok($r,$from,$to,['updated_at','captured_at','created_at','last_checked_at']);
        }));
    };

    $snapshot['claims']=$o['claim_ids']?research_report_studio_selected_claims($pdo,$projectId,$o['claim_ids']):$filterIds((array)($snapshot['claims']??[]),'public_id',[]);
    if($focus!=='')$snapshot['claims']=array_values(array_filter($snapshot['claims'],fn($r)=>research_report_studio_row_matches_focus($r,$focus,['statement','resolution_note','claim_type','status'])));
    $snapshot['findings']=$o['finding_ids']?research_report_studio_selected_findings($pdo,$projectId,$o['finding_ids']):$filterIds((array)($snapshot['findings']??[]),'public_id',[]);
    if($focus!=='')$snapshot['findings']=array_values(array_filter($snapshot['findings'],fn($r)=>research_report_studio_row_matches_focus($r,$focus,['title','summary','status'])));
    $snapshot['entities']=$filterIds((array)($snapshot['entities']??[]),'public_id',$o['entity_ids']);
    if($focus!=='')$snapshot['entities']=array_values(array_filter($snapshot['entities'],fn($r)=>research_report_studio_row_matches_focus($r,$focus,['canonical_name','description','entity_type'])));
    $snapshot['sources']=$o['source_ids']?research_report_studio_selected_sources($pdo,$projectId,$o['source_ids']):$filterIds((array)($snapshot['sources']??[]),'public_id',[]);
    if($focus!=='')$snapshot['sources']=array_values(array_filter($snapshot['sources'],fn($r)=>research_report_studio_row_matches_focus($r,$focus,['title','domain','status'])));

    if($scoped){$claimSet=array_column($snapshot['claims'],'public_id');$entitySet=array_column($snapshot['entities'],'public_id');
        $snapshot['claim_relations']=array_values(array_filter((array)($snapshot['claim_relations']??[]),fn($r)=>in_array((string)($r['source_public_id']??''),$claimSet,true)&&in_array((string)($r['target_public_id']??''),$claimSet,true)));
        $snapshot['entity_relations']=array_values(array_filter((array)($snapshot['entity_relations']??[]),fn($r)=>in_array((string)($r['source_public_id']??''),$entitySet,true)&&in_array((string)($r['target_public_id']??''),$entitySet,true)));
    }

    $selectedIds=array_values(array_unique(array_merge($o['source_ids'],$o['claim_ids'],$o['finding_ids'],$o['entity_ids'])));
    $snapshot['timeline']=array_values(array_filter((array)($snapshot['timeline']??[]),function($r)use($from,$to,$selectedIds,$focus){
        if(!research_report_studio_date_ok($r,$from,$to,['created_at','occurred_at','updated_at']))return false;
        $blob=json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if($selectedIds&&!array_filter($selectedIds,fn($id)=>str_contains((string)$blob,$id)))return false;
        return $focus===''||research_report_studio_row_matches_focus($r,$focus,['title','body','detail','event','type']);
    }));
    $snapshot['monitoring']['events']=array_values(array_filter((array)($snapshot['monitoring']['events']??[]),function($r)use($from,$to,$o,$focus){
        if(!research_report_studio_date_ok($r,$from,$to,['occurred_at','created_at']))return false;
        $blob=json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if($o['source_ids']&&!array_filter($o['source_ids'],fn($id)=>str_contains((string)$blob,$id)))return false;
        return $focus===''||research_report_studio_row_matches_focus($r,$focus,['summary','event_type','detail']);
    }));

    $results=[];$seen=[];$folders=$o['folder_ids']?:[''];
    foreach($folders as $folder){
        $filters=['date_from'=>$from,'date_to'=>$to,'exclude_report_derivatives'=>true,'stable_scoring'=>true];if($folder!=='')$filters['folder_id']=$folder;
        try{$search=research_retrieval_search($pdo,$config,$viewer,(string)$agent['project_public_id'],$focus,$filters,60,false);
            foreach((array)($search['results']??[]) as $r){
                $type=(string)($r['object_type']??'');$id=(string)($r['public_id']??'');$m=(array)($r['metadata']??[]);
                if($explicit){
                    $matches=false;
                    if($o['source_ids']&&($type==='source'?in_array($id,$o['source_ids'],true):in_array((string)($m['source_public_id']??''),$o['source_ids'],true)))$matches=true;
                    if($o['claim_ids']&&$type==='claim'&&in_array($id,$o['claim_ids'],true))$matches=true;
                    if($o['finding_ids']&&$type==='finding'&&in_array($id,$o['finding_ids'],true))$matches=true;
                    if($o['entity_ids']&&$type==='entity'&&in_array($id,$o['entity_ids'],true))$matches=true;
                    if($o['folder_ids']&&!empty($r['folder_public_id'])&&in_array((string)$r['folder_public_id'],$o['folder_ids'],true))$matches=true;
                    if(!$matches)continue;
                }
                $k=$type.':'.$id;if($id!==''&&!isset($seen[$k])){$seen[$k]=1;$results[]=$r;}
            }
        }catch(Throwable $e){}
    }
    $snapshot['recent_evidence']=array_slice($results,0,research_report_studio_limit(['studio'=>$o],12,30,60));

    if($scoped){
        $workspace=(array)($snapshot['workspace']??[]);
        $filterWorkspace=function(array $rows,string $kind)use($o,$focus): array{
            return array_values(array_filter($rows,function($r)use($kind,$o,$focus){
                if($kind==='claim'&&$o['claim_ids']&&!in_array((string)($r['claim_id']??''),$o['claim_ids'],true))return false;
                if($kind==='source'&&$o['source_ids']&&!in_array((string)($r['source_public_id']??$r['ref_id']??''),$o['source_ids'],true))return false;
                if($kind==='action'&&$o['claim_ids']&&($r['ref_type']??'')==='claim'&&!in_array((string)($r['ref_id']??''),$o['claim_ids'],true))return false;
                if($kind==='action'&&$o['source_ids']&&($r['ref_type']??'')==='source'&&!in_array((string)($r['ref_id']??''),$o['source_ids'],true))return false;
                return $focus===''||research_report_studio_row_matches_focus($r,$focus,['title','detail','reason','domain','latest_diff','statement']);
            }));
        };
        $workspace['claims']=$snapshot['claims'];
        $workspace['gaps']=$filterWorkspace((array)($workspace['gaps']??[]),'claim');
        $workspace['conflicts']=$filterWorkspace((array)($workspace['conflicts']??[]),'claim');
        $workspace['source_risks']=$filterWorkspace((array)($workspace['source_risks']??[]),'source');
        $workspace['next_actions']=$filterWorkspace((array)($workspace['next_actions']??[]),'action');
        $workspace['annotation_links']=[];$workspace['entities']=$snapshot['entities'];$workspace['recent_activity']=$snapshot['timeline'];
        $snapshot['workspace']=$workspace;
        if($explicit){$snapshot['tasks']['items']=[];$snapshot['programs']['items']=[];}
        elseif($focus!==''){$snapshot['tasks']['items']=array_values(array_filter((array)($snapshot['tasks']['items']??[]),fn($r)=>research_report_studio_row_matches_focus($r,$focus,['title','description','task_type','status'])));$snapshot['programs']['items']=array_values(array_filter((array)($snapshot['programs']['items']??[]),fn($r)=>research_report_studio_row_matches_focus($r,$focus,['title','objective','status','cadence'])));}
        $snapshot['workspace']['counts']=['sources'=>count($snapshot['sources']),'annotations'=>count(array_filter($snapshot['recent_evidence'],fn($r)=>(string)($r['object_type']??'')==='annotation')),'claims'=>count($snapshot['claims']),'findings'=>count($snapshot['findings']),'open_tasks'=>count((array)$snapshot['tasks']['items']),'recent_source_changes'=>count($snapshot['monitoring']['events'])];
        $snapshot['tasks']['summary']=[];$snapshot['programs']['summary']=[];$snapshot['monitoring']['summary']=[];$snapshot['extended_intelligence']=[];
        $snapshot['retrieval_index']=['status'=>$snapshot['retrieval_index']['status']??'ready','scoped'=>true];
    }

    $snapshot['studio']=$o;
    $counts=['claims'=>count($snapshot['claims']),'findings'=>count($snapshot['findings']),'entities'=>count($snapshot['entities']),'sources'=>count($snapshot['sources']),'recent_evidence'=>count($snapshot['recent_evidence'])];
    $snapshot['coverage']=$scoped?['scoped'=>true,'totals'=>$counts,'included'=>$counts,'truncated'=>[],'scoped_counts'=>$counts]:array_merge((array)($snapshot['coverage']??[]),['scoped_counts'=>$counts]);
    if($scoped){
        $evidenceState=[];foreach((array)$snapshot['recent_evidence'] as $r){
            $evidenceState[]=[
              'object_type'=>(string)($r['object_type']??''),'public_id'=>(string)($r['public_id']??''),
              'updated_at'=>(string)($r['updated_at']??''),'title'=>(string)($r['title']??''),
              'heading'=>(string)($r['heading']??''),'snippet'=>(string)($r['snippet']??''),
              'locator_type'=>(string)($r['locator_type']??''),'locator_label'=>(string)($r['locator_label']??''),
              'metadata'=>(array)($r['metadata']??[])
            ];
        }
        usort($evidenceState,fn($a,$b)=>strcmp($a['object_type'].':'.$a['public_id'],$b['object_type'].':'.$b['public_id']));
        $basis=[
          'schema'=>'annotated-report-studio-scoped-state-v1',
          'agent_public_id'=>(string)($snapshot['agent']['public_id']??''),
          'project_public_id'=>(string)($snapshot['project']['public_id']??''),
          'studio'=>$o,
          'claims'=>(array)$snapshot['claims'],'findings'=>(array)$snapshot['findings'],
          'entities'=>(array)$snapshot['entities'],'sources'=>(array)$snapshot['sources'],
          'claim_relations'=>(array)$snapshot['claim_relations'],'entity_relations'=>(array)$snapshot['entity_relations'],
          'timeline'=>(array)$snapshot['timeline'],'monitoring_events'=>(array)($snapshot['monitoring']['events']??[]),
          'tasks'=>(array)($snapshot['tasks']['items']??[]),'programs'=>(array)($snapshot['programs']['items']??[]),
          'recent_evidence'=>$evidenceState
        ];
    }else{
        $basis=$snapshot;unset($basis['generated_at'],$basis['state_hash'],$basis['longitudinal']);
        $basis['retrieval_index']=['input_hash'=>(string)($snapshot['retrieval_index']['input_hash']??'')];
    }
    $snapshot['state_hash']=hash('sha256',json_encode($basis,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
    return $snapshot;
}

function research_report_studio_sections(string $html): array {
    $sections=[];$seen=[];$pattern='/<h2>(.*?)<\/h2>(.*?)(?=<h2>|<hr\b|$)/si';
    if(preg_match_all($pattern,$html,$m,PREG_SET_ORDER)){
        foreach($m as $row){$label=trim(strip_tags((string)$row[1]));if($label==='')continue;$base=strtolower(trim((string)preg_replace('/[^a-z0-9]+/i','_',$label),'_'));$key=$base!==''?$base:'section';
            $n=($seen[$key]??0)+1;$seen[$key]=$n;if($n>1)$key.='_'.$n;$body=(string)$row[2];
            $sections[]=['key'=>$key,'label'=>$label,'html'=>'<h2>'.research_system_report_escape($label).'</h2>'.$body,'plain_text'=>trim(preg_replace('/\s+/u',' ',strip_tags($body)))];
        }
    }
    return $sections;
}

function research_report_studio_filter_render(array $render,array $options): array {
    $sections=research_report_studio_sections((string)($render['html']??''));$wanted=research_report_studio_clean_ids($options['include_sections']??[],40);
    if(!$wanted)return [$render,$sections];
    $kept=[];$original=(string)($render['html']??'');$firstH2=stripos($original,'<h2>');$html=$firstH2===false?'<h1>'.research_system_report_escape((string)($render['title']??'Research Report')).'</h1>':substr($original,0,$firstH2);
    foreach($sections as $section)if(in_array((string)$section['key'],$wanted,true)){$kept[]=$section;$html.=(string)$section['html'];}
    if(!$kept)throw new InvalidArgumentException('None of the selected report sections are available for this report type.');
    $html.='<hr><p><small>This Report Run contains only the sections selected in Report Studio. The recorded provenance and data-state hash identify the scoped Research data used for generation.</small></p>';
    $render['html']=$html;return [$render,$kept];
}

function research_report_studio_manifest(array $snapshot): array {
    $out=[];$map=[
      'claims'=>['statement','status','evidence_count','supports_count','contradicts_count'],
      'findings'=>['title','summary','status','claim_count'],
      'entities'=>['canonical_name','entity_type','status','mention_count','relation_count'],
      'sources'=>['title','domain','status','version_number','captured_at','last_checked_at','changed_30d'],
    ];
    foreach($map as $bucket=>$fields){$out[$bucket]=[];foreach((array)($snapshot[$bucket]??[]) as $row){$id=(string)($row['public_id']??'');if($id==='')continue;$v=[];foreach($fields as $f)$v[$f]=$row[$f]??null;$out[$bucket][$id]=hash('sha256',json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));}}
    foreach(['tasks','programs'] as $bucket){$out[$bucket]=[];foreach((array)($snapshot[$bucket]['items']??[]) as $row){$id=(string)($row['public_id']??'');if($id==='')continue;$out[$bucket][$id]=hash('sha256',json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));}}
    return $out;
}

function research_report_studio_compare_manifests(array $old,array $new): array {
    $diff=[];$material=0;
    foreach(array_unique(array_merge(array_keys($old),array_keys($new))) as $bucket){
        $a=(array)($old[$bucket]??[]);$b=(array)($new[$bucket]??[]);
        $added=array_values(array_diff(array_keys($b),array_keys($a)));$removed=array_values(array_diff(array_keys($a),array_keys($b)));$changed=[];
        foreach(array_intersect(array_keys($a),array_keys($b)) as $id)if(!hash_equals((string)$a[$id],(string)$b[$id]))$changed[]=$id;
        $diff[$bucket]=['added'=>$added,'removed'=>$removed,'changed'=>$changed,'added_count'=>count($added),'removed_count'=>count($removed),'changed_count'=>count($changed)];
        if(in_array($bucket,['claims','findings','sources'],true))$material+=count($added)+count($removed)+count($changed);
    }
    $diff['material_change_count']=$material;return $diff;
}

function research_report_studio_scope_choices(PDO $pdo,array $viewer,string $agentPublic): array {
    $agent=research_system_report_agent($pdo,$viewer,$agentPublic);$projectId=(int)$agent['project_id'];$out=['sources'=>[],'claims'=>[],'findings'=>[],'entities'=>[],'folders'=>[],'programs'=>[]];
    $q=$pdo->prepare("SELECT s.public_id,COALESCE(NULLIF(s.title,''),s.domain,s.public_id) label FROM project_sources ps JOIN sources s ON s.id=ps.source_id WHERE ps.project_id=? ORDER BY label LIMIT 200");$q->execute([$projectId]);$out['sources']=$q->fetchAll()?:[];
    $q=$pdo->prepare("SELECT public_id,LEFT(statement,180) label FROM research_claims WHERE project_id=? ORDER BY updated_at DESC LIMIT 200");$q->execute([$projectId]);$out['claims']=$q->fetchAll()?:[];
    $q=$pdo->prepare("SELECT public_id,title label FROM research_findings WHERE project_id=? AND status<>'archived' ORDER BY updated_at DESC LIMIT 200");$q->execute([$projectId]);$out['findings']=$q->fetchAll()?:[];
    $q=$pdo->prepare("SELECT public_id,canonical_name label FROM research_entities WHERE project_id=? AND status<>'archived' ORDER BY canonical_name LIMIT 200");$q->execute([$projectId]);$out['entities']=$q->fetchAll()?:[];
    $q=$pdo->prepare("SELECT public_id,title label FROM research_workspace_objects WHERE project_id=? AND object_type='folder' AND status='active' ORDER BY title LIMIT 200");$q->execute([$projectId]);$out['folders']=$q->fetchAll()?:[];
    if(function_exists('research_programs_ready')&&research_programs_ready($pdo)){$q=$pdo->prepare("SELECT public_id,title label FROM research_programs WHERE research_agent_id=? AND status<>'archived' ORDER BY updated_at DESC LIMIT 100");$q->execute([(int)$agent['id']]);$out['programs']=$q->fetchAll()?:[];}
    return $out;
}

function research_report_studio_previous_run(PDO $pdo,array $viewer,array $report): ?array {
    $q=$pdo->prepare("SELECT public_id FROM research_system_reports WHERE research_agent_id=? AND report_type=? AND status='ready' AND id<? ORDER BY id DESC LIMIT 1");
    $q->execute([(int)$report['research_agent_id'],(string)$report['report_type'],(int)$report['id']]);$id=(string)($q->fetchColumn()?:'');
    return $id!==''?research_system_report_access($pdo,$viewer,$id):null;
}

function research_report_studio_preset_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_report_studio_ready($pdo))return null;
    $q=$pdo->prepare("SELECT rrp.*,ra.public_id agent_public_id,rp.public_id project_public_id,rprog.public_id program_public_id,rprog.title program_title FROM research_report_presets rrp
      JOIN research_agents ra ON ra.id=rrp.research_agent_id JOIN research_projects rp ON rp.id=rrp.project_id
      LEFT JOIN research_programs rprog ON rprog.id=rrp.program_id WHERE rrp.public_id=? LIMIT 1");
    $q->execute([trim($publicId)]);$row=$q->fetch();if(!$row||!research_agent_access($pdo,$viewer,(string)$row['agent_public_id']))return null;
    $row['parameters']=json_decode((string)($row['parameters_json']??''),true)?:[];$row['scope']=json_decode((string)($row['scope_json']??''),true)?:[];return $row;
}

function research_report_studio_preset_list(PDO $pdo,array $viewer,string $agentPublic): array {
    $agent=research_system_report_agent($pdo,$viewer,$agentPublic);$q=$pdo->prepare("SELECT public_id FROM research_report_presets WHERE research_agent_id=? AND status='active' ORDER BY updated_at DESC,id DESC");$q->execute([(int)$agent['id']]);
    $out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $id){$r=research_report_studio_preset_access($pdo,$viewer,(string)$id);if($r)$out[]=$r;}return $out;
}

function research_report_studio_preset_save(PDO $pdo,array $viewer,string $agentPublic,array $input): array {
    $agent=research_system_report_agent($pdo,$viewer,$agentPublic);$project=research_agent_workspace_project($pdo,$viewer,$agentPublic);if(!$project)throw new RuntimeException('Research Agent workspace not found.');research_agent_workspace_require_write($project);
    $name=mb_substr(trim((string)($input['name']??'')),0,190);if($name==='')throw new InvalidArgumentException('Preset name is required.');
    $type=research_system_report_type((string)($input['report_type']??''));$options=research_report_studio_options($input);$title=mb_substr(trim((string)($input['title_template']??$input['title']??'')),0,255);
    $programId=null;$programPublic=trim((string)($input['program_id']??''));
    if($programPublic!==''){if(!function_exists('research_program_access'))throw new RuntimeException('Research Programs are unavailable.');$program=research_program_access($pdo,$viewer,$programPublic);if(!$program||(int)$program['research_agent_id']!==(int)$agent['id'])throw new InvalidArgumentException('Selected Research Program does not belong to this Research Agent.');$programId=(int)$program['id'];}
    $public=ulid_like();$pdo->prepare("INSERT INTO research_report_presets(public_id,research_agent_id,project_id,program_id,created_by_user_id,name,report_type,title_template,parameters_json,scope_json)
      VALUES(?,?,?,?,?,?,?,?,?,?)")->execute([$public,(int)$agent['id'],(int)$agent['project_id'],$programId,(int)$viewer['id'],$name,$type['key'],$title?:null,
      json_encode(['depth'=>$options['depth'],'focus_query'=>$options['focus_query'],'date_from'=>$options['date_from'],'date_to'=>$options['date_to'],'include_sections'=>$options['include_sections']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
      json_encode(['source_ids'=>$options['source_ids'],'claim_ids'=>$options['claim_ids'],'finding_ids'=>$options['finding_ids'],'entity_ids'=>$options['entity_ids'],'folder_ids'=>$options['folder_ids']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    return research_report_studio_preset_access($pdo,$viewer,$public)??['public_id'=>$public];
}

function research_report_studio_preset_archive(PDO $pdo,array $viewer,string $agentPublic,string $presetPublic): array {
    $p=research_report_studio_preset_access($pdo,$viewer,$presetPublic);if(!$p||!hash_equals((string)$p['agent_public_id'],$agentPublic))throw new RuntimeException('Report preset not found.');
    $project=research_agent_workspace_project($pdo,$viewer,$agentPublic);if(!$project)throw new RuntimeException('Research Agent workspace not found.');research_agent_workspace_require_write($project);
    $pdo->prepare("UPDATE research_report_presets SET status='archived',updated_at=NOW() WHERE id=?")->execute([(int)$p['id']]);$p['status']='archived';return $p;
}

function research_report_studio_preset_options(array $preset): array {
    return array_merge((array)($preset['parameters']??[]),(array)($preset['scope']??[]));
}

function research_report_studio_compare(PDO $pdo,array $config,array $viewer,string $olderPublic,string $newerPublic): array {
    $old=research_system_report_access($pdo,$viewer,$olderPublic);$new=research_system_report_access($pdo,$viewer,$newerPublic);
    if(!$old||!$new||(int)$old['research_agent_id']!==(int)$new['research_agent_id'])throw new RuntimeException('Reports must belong to the same Research Agent.');
    $a=json_decode((string)($old['knowledge_manifest_json']??''),true)?:[];$b=json_decode((string)($new['knowledge_manifest_json']??''),true)?:[];
    return ['older'=>$old,'newer'=>$new,'diff'=>research_report_studio_compare_manifests($a,$b),'state_changed'=>!hash_equals((string)$old['input_state_hash'],(string)$new['input_state_hash'])];
}

function research_report_studio_freshness(PDO $pdo,array $config,array $viewer,array $report): array {
    $agent=research_system_report_agent($pdo,$viewer,(string)$report['agent_public_id']);$snapshot=research_system_report_snapshot($pdo,$config,$viewer,$agent);
    $opts=array_merge(json_decode((string)($report['parameters_json']??''),true)?:[],json_decode((string)($report['scope_json']??''),true)?:[]);
    $snapshot=research_report_studio_scope_snapshot($pdo,$config,$viewer,$agent,$snapshot,$opts);
    if(hash_equals((string)$report['input_state_hash'],(string)$snapshot['state_hash']))$state='current';
    else{
        $old=json_decode((string)($report['knowledge_manifest_json']??''),true)?:[];$d=research_report_studio_compare_manifests($old,research_report_studio_manifest($snapshot));$manifestChanges=0;
        foreach($d as $bucket=>$delta)if($bucket!=='material_change_count'&&is_array($delta))$manifestChanges+=(int)($delta['added_count']??0)+(int)($delta['removed_count']??0)+(int)($delta['changed_count']??0);
        $age=time()-strtotime((string)$report['created_at']);
        $state=$manifestChanges===0?'current':($d['material_change_count']>0?'materially_changed':($age>30*86400?'stale':'changed'));
    }
    return ['state'=>$state,'current_state_hash'=>$snapshot['state_hash']];
}

function research_report_studio_run_preset(PDO $pdo,array $config,array $viewer,string $agentPublic,string $presetPublic,bool $byAgent=false,string $generationMode=''): array {
    $preset=research_report_studio_preset_access($pdo,$viewer,$presetPublic);if(!$preset||!hash_equals((string)$preset['agent_public_id'],$agentPublic)||($preset['status']??'')!=='active')throw new RuntimeException('Report preset not found.');
    $mode=$generationMode!==''?$generationMode:($byAgent?'agent':'user');if(!in_array($mode,['user','agent','program','legacy'],true))$mode=$byAgent?'agent':'user';
    $opts=research_report_studio_preset_options($preset);$report=research_system_report_generate($pdo,$config,$viewer,$agentPublic,(string)$preset['report_type'],(string)($preset['title_template']??''),$byAgent,null,$opts,(int)$preset['id'],null,$mode);
    $pdo->prepare('UPDATE research_report_presets SET last_run_at=NOW(),updated_at=NOW() WHERE id=?')->execute([(int)$preset['id']]);return $report;
}

function research_report_studio_refresh(PDO $pdo,array $config,array $viewer,string $agentPublic,string $reportPublic): array {
    $report=research_system_report_access($pdo,$viewer,$reportPublic);if(!$report||!hash_equals((string)$report['agent_public_id'],$agentPublic))throw new RuntimeException('Report Run not found.');
    $opts=array_merge((array)$report['parameters'],(array)$report['scope']);
    return research_system_report_generate($pdo,$config,$viewer,$agentPublic,(string)$report['report_type'],(string)$report['title'],false,null,$opts,$report['preset_id']!==null?(int)$report['preset_id']:null,(int)$report['id'],'user');
}

function research_report_studio_create_document(PDO $pdo,array $viewer,string $agentPublic,string $reportPublic,array $sectionKeys=[]): array {
    $report=research_system_report_access($pdo,$viewer,$reportPublic);if(!$report||!hash_equals((string)$report['agent_public_id'],$agentPublic))throw new RuntimeException('Report Run not found.');
    $project=research_agent_workspace_project($pdo,$viewer,$agentPublic);if(!$project)throw new RuntimeException('Research Agent workspace not found.');research_agent_workspace_require_write($project);
    if(!empty($report['document_public_id'])){$doc=research_agent_workspace_object($pdo,$viewer,(string)$report['document_public_id'],false);if($doc)return $doc;}
    $sections=json_decode((string)($report['sections_json']??''),true)?:[];$wanted=research_report_studio_clean_ids($sectionKeys,40);$html='';
    if($wanted){foreach($sections as $s)if(in_array((string)($s['key']??''),$wanted,true))$html.=(string)($s['html']??'');}
    if(trim($html)==='')$html=(string)($report['rendered_html']??'');if(trim($html)==='')throw new RuntimeException('This report has no rendered content.');
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{$lock=$pdo->prepare('SELECT id,document_object_id FROM research_system_reports WHERE id=? FOR UPDATE');$lock->execute([(int)$report['id']]);$locked=$lock->fetch();if(!$locked)throw new RuntimeException('Report Run not found.');
        if(!empty($locked['document_object_id'])){$q=$pdo->prepare('SELECT public_id FROM research_workspace_objects WHERE id=?');$q->execute([(int)$locked['document_object_id']]);$existing=(string)($q->fetchColumn()?:'');if($owns)$pdo->commit();if($existing!==''){return research_agent_workspace_object($pdo,$viewer,$existing,false)??['public_id'=>$existing];}}
        $folder=research_system_report_folder($pdo,$viewer,$project);$doc=research_agent_workspace_create_document($pdo,$viewer,$project,['title'=>(string)$report['title'],'document_type'=>'report','content_html'=>$html,'summary'=>(string)($report['rendered_summary']??''),'parent_id'=>(string)$folder['public_id']],false);
        $pdo->prepare('UPDATE research_system_reports SET document_object_id=?,document_created_at=NOW(),updated_at=NOW() WHERE id=?')->execute([(int)$doc['id'],(int)$report['id']]);
        research_system_report_event($pdo,(int)$report['id'],'document_created','user',(int)$viewer['id'],['document_id'=>$doc['public_id'],'sections'=>$wanted]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    research_system_report_queue_followups($pdo,(int)$report['id'],(int)$report['project_id'],(int)$viewer['id'],'A Research document was created from a Report Run.');
    return $doc;
}
