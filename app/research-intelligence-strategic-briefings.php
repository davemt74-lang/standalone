<?php
declare(strict_types=1);

/**
 * Phase 73 Section 6 — Executive Strategic Briefings & Team Review.
 *
 * Strategic Briefings remain normal Executive Briefings backed by normal Research
 * Docs. This layer freezes the Phase 73 strategic packet used to render the
 * document and binds the document to the existing Collaborative Review engine.
 * Phase 59 remains the only publication workflow.
 */

function research_intelligence_strategic_briefings_ready(PDO $pdo): bool {
    try{
        return installer_table_exists($pdo,'research_intelligence_strategic_briefings')
          && research_intelligence_strategic_reviews_ready($pdo)
          && research_reviews_ready($pdo)
          && research_intelligence_portfolios_ready($pdo);
    }catch(Throwable $e){return false;}
}
function research_intelligence_strategic_briefing_json(mixed $value): array {
    if(is_array($value))return $value;
    if(is_string($value)&&trim($value)!==''){try{$x=json_decode($value,true,512,JSON_THROW_ON_ERROR);return is_array($x)?$x:[];}catch(Throwable $e){return [];}}
    return [];
}
function research_intelligence_strategic_briefing_render_sections(array $packet,?array $sourceReview=null): string {
    $state=(array)($packet['state']??[]);$execution=(array)($state['execution']['summary']??[]);$patterns=(array)($state['pattern_memory']??[]);$graph=(array)($state['strategic_graph']['summary']??[]);$focus=(array)($state['review_focus']??[]);
    $h=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $html='<hr><h2>Strategic decision & execution</h2><p><em>This section is rendered deterministically from a frozen Phase 73 strategic packet. It is not an Agent decision.</em></p><ul>';
    foreach([
      'Native Decisions'=>(int)($execution['native_decisions']??0),
      'Action Plans'=>(int)($execution['action_plans']??0),
      'Overdue Action Plans'=>(int)($execution['overdue_action_plans']??0),
      'Material open variances'=>(int)($execution['material_open_variances']??0),
      'High / critical open variances'=>(int)($execution['high_or_critical_open_variances']??0),
      'Completed without Outcome Memory'=>(int)($execution['completed_without_outcome']??0)
    ] as $label=>$value)$html.='<li><strong>'.$h($label).':</strong> '.$h($value).'</li>';
    $html.='</ul><h2>Strategic review focus</h2>';
    if(!$focus)$html.='<p>No deterministic strategic focus items are present in this packet.</p>';else{$html.='<ul>';foreach(array_slice($focus,0,30) as $item)$html.='<li><strong>'.$h(strtoupper((string)($item['severity']??'info'))).'</strong> · '.$h((string)($item['summary']??'')).'</li>';$html.='</ul>';}
    $patternRows=(array)($patterns['patterns']??[]);$html.='<h2>Cross-Decision learning</h2>';
    if(!$patternRows)$html.='<p>No active deterministic cross-Decision learning patterns are present.</p>';else{$html.='<ul>';foreach(array_slice($patternRows,0,20) as $item)$html.='<li><strong>'.$h((string)($item['label']??'Pattern')).'</strong> · '.$h((string)($item['decision_count']??0)).' Decisions · '.$h((string)($item['evidence_count']??0)).' evidence member(s)</li>';$html.='</ul>';}
    $html.='<h2>Strategic dependency & conflict graph</h2><ul>';
    foreach([
      'Relationships'=>(int)($graph['active_edges']??0),
      'Conflicts'=>(int)($graph['conflicts']??0),
      'Blocking relationships'=>(int)($graph['blocks']??0),
      'Unresolved dependencies'=>(int)($graph['unresolved_dependencies']??0),
      'Stale relationships'=>(int)($graph['stale_edges']??0)
    ] as $label=>$value)$html.='<li><strong>'.$h($label).':</strong> '.$h($value).'</li>';
    $html.='</ul>';
    if($sourceReview){
        $agg=(array)($sourceReview['review_aggregate']??[]);$review=$sourceReview['review']??null;
        $html.='<h2>Strategic Review provenance</h2><p>Source Strategic Review packet: <strong>'.$h((string)$sourceReview['public_id']).'</strong>';
        if($review)$html.=' · '.$h(strtoupper((string)$review['status'])).' · '.$h(str_replace('_',' ',(string)($agg['consensus']??'awaiting_reviewers')));
        $html.='.</p>';
    }
    $html.='<h2>Governance boundary</h2><p>This briefing can summarize and communicate strategic state. Team Review and publication do not change Decision lifecycle, Action Plan lifecycle, execution variance, Strategic Graph edges, Outcome Memory, Research Tasks, or Agent authority.</p>';
    return $html;
}
function research_intelligence_strategic_briefing_render_document(array $portfolio,array $packet,?array $sourceReview=null): string {
    $state=(array)($packet['state']??[]);$aggregate=(array)($state['aggregate']??[]);$summary=(array)($aggregate['summary']??[]);$h=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $html='<h1>'.$h((string)$portfolio['title']).' — Executive Strategic Briefing</h1><p>'.$h((string)$portfolio['objective']).'</p>';
    $html.='<p><strong>Frozen strategic packet:</strong> '.$h((string)($packet['captured_at']??'')).' · <strong>State hash:</strong> '.$h((string)($packet['state_hash']??'')).'</p>';
    $html.='<h2>Portfolio overview</h2><ul>';
    foreach([
      'Programs'=>(int)($summary['programs']??0),
      'Active programs'=>(int)($summary['active']??0),
      'Material changes'=>(int)($summary['material_changes']??0),
      'High-priority changes'=>(int)($summary['high_changes']??0),
      'Failed runs · 30d'=>(int)($summary['failed_runs_30d']??0),
      'Stale evidence'=>(int)($summary['stale_sources']??0)
    ] as $label=>$value)$html.='<li><strong>'.$h($label).':</strong> '.$h($value).'</li>';
    $html.='</ul>';
    $section=function(string $title,array $rows)use(&$html,$h): void {$html.='<h2>'.$h($title).'</h2>';if(!$rows){$html.='<p>No current items in the frozen packet.</p>';return;}$html.='<ul>';foreach(array_slice($rows,0,20) as $row)$html.='<li><strong>'.$h((string)($row['program_title']??$row['kind']??'Portfolio')).':</strong> '.$h((string)($row['summary']??$row['key']??'')).'</li>';$html.='</ul>';};
    $section('Risks',(array)($aggregate['risks']??[]));$section('Opportunities',(array)($aggregate['opportunities']??[]));$section('Cross-program signals',(array)($aggregate['cross_program']??[]));
    $trends=(array)($aggregate['trends']??[]);$html.='<h2>What changed</h2>';if(!$trends)$html.='<p>No material deltas are present in this frozen packet.</p>';else{$html.='<ul>';foreach(array_slice($trends,0,20,true) as $type=>$count)$html.='<li>'.$h(str_replace('_',' ',(string)$type)).': '.$h((string)$count).'</li>';$html.='</ul>';}
    return $html.research_intelligence_strategic_briefing_render_sections($packet,$sourceReview);
}
function research_intelligence_strategic_briefing_access(PDO $pdo,array $viewer,string $publicId,bool $includeDrift=false): ?array {
    if(!research_intelligence_strategic_briefings_ready($pdo))return null;
    $q=$pdo->prepare("SELECT sb.*,eb.public_id executive_briefing_public_id,eb.title,eb.status executive_status,
      p.public_id portfolio_public_id,p.title portfolio_title,rwo.public_id document_public_id,rp.public_id project_public_id,
      rr.public_id team_review_public_id,pw.public_id publication_public_id
      FROM research_intelligence_strategic_briefings sb
      JOIN research_executive_briefings eb ON eb.id=sb.executive_briefing_id
      JOIN research_intelligence_portfolios p ON p.id=sb.portfolio_id
      JOIN research_workspace_objects rwo ON rwo.id=eb.document_object_id
      JOIN research_projects rp ON rp.id=rwo.project_id
      LEFT JOIN research_reviews rr ON rr.id=sb.collaborative_review_id
      LEFT JOIN research_publication_workflows pw ON pw.id=eb.publication_workflow_id
      WHERE sb.public_id=? LIMIT 1");
    $q->execute([trim($publicId)]);$r=$q->fetch();if(!$r)return null;
    if(!research_intelligence_portfolio_access($pdo,$viewer,(string)$r['portfolio_public_id']))return null;
    $r['packet']=research_intelligence_strategic_briefing_json($r['packet_json']??null);unset($r['packet_json']);
    $r['team_review']=$r['team_review_public_id']?research_review_access($pdo,$viewer,(string)$r['team_review_public_id']):null;
    $r['team_review_aggregate']=$r['team_review']?research_review_aggregate($pdo,$r['team_review']):[];
    $r['publication_ready']=false;
    if($r['team_review']&&($r['team_review']['status']??'')==='completed'&&empty($r['team_review']['is_stale'])&&($r['team_review_aggregate']['consensus']??'')==='unanimous_approval')$r['publication_ready']=true;
    if($includeDrift){
        $current=research_intelligence_strategic_review_packet($pdo,$viewer,(string)$r['portfolio_public_id']);
        $r['current_state_hash']=$current['state_hash'];$r['current_drift']=!hash_equals((string)$r['packet_hash'],(string)$current['state_hash']);
    }
    return $r;
}
function research_intelligence_strategic_briefing_for_executive(PDO $pdo,array $viewer,string $briefingPublic,bool $includeDrift=false): ?array {
    if(!research_intelligence_strategic_briefings_ready($pdo))return null;
    $q=$pdo->prepare("SELECT sb.public_id FROM research_intelligence_strategic_briefings sb JOIN research_executive_briefings eb ON eb.id=sb.executive_briefing_id WHERE eb.public_id=? LIMIT 1");
    $q->execute([trim($briefingPublic)]);$public=(string)($q->fetchColumn()?:'');return $public!==''?research_intelligence_strategic_briefing_access($pdo,$viewer,$public,$includeDrift):null;
}
function research_intelligence_strategic_briefing_assert_publication_ready(PDO $pdo,array $viewer,string $briefingPublic): void {
    $strategic=research_intelligence_strategic_briefing_for_executive($pdo,$viewer,$briefingPublic,false);if(!$strategic)return;
    $review=$strategic['team_review']??null;$agg=(array)($strategic['team_review_aggregate']??[]);
    if(!$review)throw new RuntimeException('Strategic Briefing requires Team Review before publication.');
    if(($review['status']??'')!=='completed')throw new RuntimeException('Complete the Strategic Briefing Team Review before publication.');
    if(!empty($review['is_stale']))throw new RuntimeException('Strategic Briefing Team Review is stale because the briefing document changed. Start an updated review before publication.');
    if(($agg['consensus']??'')!=='unanimous_approval')throw new RuntimeException('Strategic Briefing publication requires unanimous Team Review approval.');
}
function research_intelligence_strategic_briefing_create(PDO $pdo,array $viewer,string $portfolioPublic,array $input=[]): array {
    if(!research_intelligence_strategic_briefings_ready($pdo))throw new RuntimeException('Executive Strategic Briefings require the latest database upgrade.');
    $p=research_intelligence_portfolio_access($pdo,$viewer,trim($portfolioPublic));if(!$p||!research_intelligence_portfolio_can_write($p))throw new RuntimeException('Strategic Briefing write access is unavailable.');
    $settings=research_intelligence_strategic_review_settings($pdo,$viewer,(string)$p['public_id']);$resolved=research_intelligence_strategic_review_resolve_reviewers($pdo,$viewer,$p,$settings);
    $slot=trim((string)($input['idempotency_key']??''));if($slot==='')$slot=ulid_like();$dedupe=hash('sha256','strategic-briefing|'.$p['id'].'|'.$slot);
    $q=$pdo->prepare('SELECT public_id FROM research_intelligence_strategic_briefings WHERE dedupe_key=? LIMIT 1');$q->execute([$dedupe]);$existing=(string)($q->fetchColumn()?:'');if($existing!=='')return research_intelligence_strategic_briefing_access($pdo,$viewer,$existing,true)??[];

    $source=null;$sourceId=null;$sourceConsensus=null;$sourceCompleted=null;$sourcePublic=trim((string)($input['strategic_review_id']??''));
    if($sourcePublic!==''){
        $source=research_intelligence_strategic_review_access($pdo,$viewer,$sourcePublic,false);if(!$source||(string)$source['portfolio_public_id']!==(string)$p['public_id'])throw new RuntimeException('Source Strategic Review is unavailable for this Portfolio.');
        $sourceId=(int)$source['id'];$sourceConsensus=(string)($source['review_aggregate']['consensus']??'awaiting_reviewers');$sourceCompleted=$source['review']['completed_at']??null;$packet=(array)$source['packet'];
    }else $packet=research_intelligence_strategic_review_packet($pdo,$viewer,(string)$p['public_id']);
    $packetHash=(string)($packet['state_hash']??'');if($packetHash===''||strlen($packetHash)!==64)throw new RuntimeException('Strategic packet hash is unavailable.');

    $programs=research_intelligence_portfolio_programs($pdo,$viewer,$p);if(!$programs)throw new RuntimeException('Add at least one Research Program before creating a Strategic Briefing.');
    $project=$resolved['project'];if(!project_can_write($project))throw new RuntimeException('The Portfolio anchor Research project is not writable.');
    $snapshot=research_intelligence_portfolio_snapshot($pdo,$viewer,(string)$p['public_id'],max(1,min(365,(int)($input['window_days']??30))),'briefing');
    $html=research_intelligence_strategic_briefing_render_document($p,$packet,$source);
    $title=mb_substr(trim((string)($input['title']??'')),0,240);if($title==='')$title=(string)$p['title'].' — Executive Strategic Briefing — '.gmdate('Y-m-d');
    $doc=research_agent_workspace_create_document($pdo,$viewer,$project,['title'=>$title,'content_html'=>$html,'summary'=>'Executive Strategic Briefing with frozen Decision execution, Pattern Memory, Strategic Graph, and Strategic Review provenance.','document_type'=>'research_brief'],false);
    $briefPublic=ulid_like();$pdo->prepare("INSERT INTO research_executive_briefings(public_id,portfolio_id,snapshot_id,document_object_id,created_by_user_id,title) VALUES(?,?,?,?,?,?)")->execute([$briefPublic,(int)$p['id'],(int)$snapshot['id'],(int)$doc['id'],(int)$viewer['id'],$title]);$briefId=(int)$pdo->lastInsertId();
    $public=ulid_like();$packetJson=json_encode($packet,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);if($packetJson===false)throw new RuntimeException('Strategic Briefing packet could not be encoded.');
    $pdo->prepare("INSERT INTO research_intelligence_strategic_briefings(public_id,portfolio_id,executive_briefing_id,source_strategic_review_id,packet_json,packet_hash,source_review_consensus,source_review_completed_at,dedupe_key,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?)")
      ->execute([$public,(int)$p['id'],$briefId,$sourceId,$packetJson,$packetHash,$sourceConsensus?:null,$sourceCompleted,$dedupe,(int)$viewer['id']]);
    $due=date('Y-m-d H:i:s',time()+((int)($settings['due_offset_hours']??72)*3600));
    $instructions=trim((string)($input['instructions']??'Review this Executive Strategic Briefing against its frozen strategic packet. Verify that the briefing faithfully communicates Decision execution, organizational learning, dependencies/conflicts, material risks, and review focus. Do not change Decision or Action Plan state from this review.'));
    $review=research_review_create($pdo,$viewer,'document',(string)$doc['public_id'],$resolved['reviewer_ids'],$due,$instructions,$resolved['assignment_options']);
    $pdo->prepare('UPDATE research_intelligence_strategic_briefings SET collaborative_review_id=?,updated_at=NOW() WHERE public_id=?')->execute([(int)$review['id'],$public]);
    $pdo->prepare('UPDATE research_intelligence_portfolios SET last_briefed_at=NOW(),updated_at=NOW() WHERE id=?')->execute([(int)$p['id']]);
    research_intelligence_portfolio_event($pdo,(int)$p['id'],'strategic_briefing_created','user',(int)$viewer['id'],['strategic_briefing_id'=>$public,'briefing_id'=>$briefPublic,'document_id'=>$doc['public_id'],'team_review_id'=>$review['public_id'],'source_strategic_review_id'=>$sourcePublic?:null,'packet_hash'=>$packetHash]);
    return research_intelligence_strategic_briefing_access($pdo,$viewer,$public,true)??[];
}
function research_intelligence_portfolio_strategic_briefing_summary(PDO $pdo,array $viewer,string $portfolioPublic): array {
    if(!research_intelligence_strategic_briefings_ready($pdo))return ['ready'=>false,'summary'=>[],'briefings'=>[]];
    $p=research_intelligence_portfolio_access($pdo,$viewer,trim($portfolioPublic));if(!$p)return ['ready'=>false,'summary'=>[],'briefings'=>[]];
    $q=$pdo->prepare('SELECT public_id FROM research_intelligence_strategic_briefings WHERE portfolio_id=? ORDER BY id DESC LIMIT 50');$q->execute([(int)$p['id']);$rows=[];$summary=['total'=>0,'open_review'=>0,'completed_review'=>0,'approved'=>0,'changes_requested'=>0,'overdue'=>0,'current_drift'=>0,'publication_ready'=>0];
    foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $id){$r=research_intelligence_strategic_briefing_access($pdo,$viewer,(string)$id,true);if(!$r)continue;$rows[]=$r;$summary['total']++;$review=$r['team_review']??null;$agg=(array)($r['team_review_aggregate']??[]);
      if($review&&($review['status']??'')==='open'){$summary['open_review']++;if(!empty($review['due_at'])&&strtotime((string)$review['due_at'])<time())$summary['overdue']++;}
      if($review&&($review['status']??'')==='completed')$summary['completed_review']++;
      if(($agg['consensus']??'')==='unanimous_approval')$summary['approved']++;
      if(($agg['consensus']??'')==='changes_requested')$summary['changes_requested']++;
      if(!empty($r['current_drift']))$summary['current_drift']++;
      if(!empty($r['publication_ready']))$summary['publication_ready']++;
    }
    return ['ready'=>true,'summary'=>$summary,'briefings'=>$rows];
}
function research_intelligence_organization_strategic_briefing_center(PDO $pdo,array $viewer): array {
    if(!research_intelligence_strategic_briefings_ready($pdo))return ['ready'=>false,'summary'=>[],'attention'=>[]];
    $dashboard=research_intelligence_portfolio_dashboard($pdo,$viewer);$summary=['total'=>0,'open_review'=>0,'completed_review'=>0,'approved'=>0,'changes_requested'=>0,'overdue'=>0,'current_drift'=>0,'publication_ready'=>0];$attention=[];
    foreach((array)$dashboard['portfolios'] as $p){$x=research_intelligence_portfolio_strategic_briefing_summary($pdo,$viewer,(string)$p['public_id']);if(!$x['ready'])continue;foreach(array_keys($summary) as $k)$summary[$k]+=(int)($x['summary'][$k]??0);
      foreach((array)$x['briefings'] as $r){$review=$r['team_review']??null;$agg=(array)($r['team_review_aggregate']??[]);$reasons=[];if($review&&($review['status']??'')==='open')$reasons[]='team_review_open';if($review&&($review['status']??'')==='open'&&!empty($review['due_at'])&&strtotime((string)$review['due_at'])<time())$reasons[]='overdue';if(($agg['consensus']??'')==='changes_requested')$reasons[]='changes_requested';if(($agg['consensus']??'')==='unresolved_objection')$reasons[]='unresolved_objection';if(!empty($r['current_drift']))$reasons[]='current_strategic_drift';
        if($reasons)$attention[]=['portfolio_id'=>(string)$p['public_id'],'portfolio_title'=>(string)$p['title'],'strategic_briefing_id'=>(string)$r['public_id'],'executive_briefing_id'=>(string)$r['executive_briefing_public_id'],'team_review_id'=>(string)($r['team_review_public_id']??''),'title'=>(string)$r['title'],'due_at'=>$review['due_at']??null,'consensus'=>$agg['consensus']??'awaiting_reviewers','reasons'=>$reasons];
      }
    }
    usort($attention,fn($a,$b)=>(in_array('overdue',$b['reasons'],true)<=>in_array('overdue',$a['reasons'],true))?:strcmp((string)($a['due_at']??'9999'),(string)($b['due_at']??'9999')));
    return ['ready'=>true,'summary'=>$summary,'attention'=>array_slice($attention,0,80),'generated_at'=>date('Y-m-d H:i:s')];
}
