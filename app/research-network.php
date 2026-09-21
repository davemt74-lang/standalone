<?php
declare(strict_types=1);

require_once __DIR__.'/research-reports.php';

function research_network_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_project_report_references')&&installer_table_exists($pdo,'research_report_version_citations');}
    catch(Throwable $e){return false;}
}

function research_network_relation_types(): array {
    return ['background'=>'Background','supports'=>'Supports','contrasts'=>'Contrasts','extends'=>'Extends','method'=>'Method / precedent','context'=>'Context'];
}

function research_network_parse_report_input(string $input,?int $version=null): array {
    $input=trim($input);if($input==='')throw new InvalidArgumentException('Report ID or URL is required.');
    $report=$input;$urlVersion=null;
    if(str_contains($input,'?')||preg_match('#^https?://#i',$input)||str_starts_with($input,'/')){
        $parts=parse_url($input);$query=[];if(!empty($parts['query']))parse_str((string)$parts['query'],$query);
        if(!empty($query['id']))$report=trim((string)$query['id']);
        if(isset($query['v'])&&(int)$query['v']>0)$urlVersion=(int)$query['v'];
    }
    if($report===''||strlen($report)>80)throw new InvalidArgumentException('Invalid Research report ID.');
    return ['report_public_id'=>$report,'version'=>$version&&$version>0?$version:$urlVersion];
}

function research_network_version_meta(PDO $pdo,string $versionPublic,?array $viewer): ?array {
    $q=$pdo->prepare("SELECT rv.id version_id,rv.public_id version_public_id,rv.version_number,rv.visibility version_visibility,rv.title version_title,rv.summary version_summary,rv.snapshot_hash,rv.created_at version_created_at,
      rr.id report_id,rr.public_id report_public_id,rr.title report_title,rr.visibility report_visibility,rr.status report_status,rr.current_version_id,rr.created_by_user_id,
      rp.id project_id,rp.public_id project_public_id,rp.title project_title,rp.team_id,rp.owner_user_id,u.display_name publisher_name,u.username publisher_username,
      crv.public_id current_version_public_id,crv.version_number current_version_number
      FROM research_report_versions rv
      JOIN research_reports rr ON rr.id=rv.report_id
      JOIN research_projects rp ON rp.id=rr.project_id
      JOIN users u ON u.id=rr.created_by_user_id
      LEFT JOIN research_report_versions crv ON crv.id=rr.current_version_id
      WHERE rv.public_id=? LIMIT 1");$q->execute([$versionPublic]);$meta=$q->fetch();if(!$meta)return null;
    $report=research_report_access($pdo,(string)$meta['report_public_id'],$viewer);if(!$report)return null;
    $version=research_report_version_access($pdo,$report,(int)$meta['version_number'],$viewer);if(!$version)return null;
    foreach(['version_id','version_number','report_id','project_id','owner_user_id','created_by_user_id','current_version_id','current_version_number'] as $k)$meta[$k]=(int)($meta[$k]??0);
    $meta['team_id']=$meta['team_id']!==null?(int)$meta['team_id']:null;$meta['report']=$report;$meta['version']=$version;return $meta;
}

function research_network_version_meta_by_id(PDO $pdo,int $versionId,?array $viewer): ?array {
    $q=$pdo->prepare('SELECT public_id FROM research_report_versions WHERE id=? LIMIT 1');$q->execute([$versionId]);$public=(string)($q->fetchColumn()?:'');return $public!==''?research_network_version_meta($pdo,$public,$viewer):null;
}

function research_network_target(PDO $pdo,array $viewer,string $reportInput,?int $version=null): ?array {
    $parsed=research_network_parse_report_input($reportInput,$version);$report=research_report_access($pdo,$parsed['report_public_id'],$viewer);if(!$report)return null;$vn=$parsed['version']?:((int)$report['version_number']);$row=research_report_version_access($pdo,$report,$vn,$viewer);if(!$row)return null;return research_network_version_meta($pdo,(string)$row['public_id'],$viewer);
}

function research_network_target_allowed_for_project(array $project,array $target): bool {
    if((int)$target['project_id']===(int)$project['id'])return false;
    if((string)$target['version_visibility']==='public')return true;
    $sourceTeam=(int)($project['team_id']??0);$targetTeam=(int)($target['team_id']??0);
    return $sourceTeam>0&&$sourceTeam===$targetTeam;
}

function research_network_publish_allowed(array $project,string $sourceVisibility,array $target): bool {
    $targetVisibility=(string)$target['version_visibility'];if($targetVisibility==='public')return true;
    $sourceTeam=(int)($project['team_id']??0);$targetTeam=(int)($target['team_id']??0);if($sourceTeam<=0||$sourceTeam!==$targetTeam)return false;
    if($sourceVisibility==='team')return $targetVisibility==='team';
    if($sourceVisibility==='private')return in_array($targetVisibility,['team','private'],true);
    return false;
}

function research_network_reference_upsert(PDO $pdo,array $viewer,string $projectPublic,string $reportInput,?int $version,string $relation,string $note=''): array {
    if(!research_network_ready($pdo))throw new RuntimeException('Research Network requires the Phase 25 database upgrade.');
    $project=project_access($pdo,(int)$viewer['id'],trim($projectPublic));if(!$project||!project_can_write($project))throw new RuntimeException('You do not have permission to edit this Research project.');
    $relation=strtolower(trim($relation));if(!isset(research_network_relation_types()[$relation]))throw new InvalidArgumentException('Invalid citation relationship.');
    $target=research_network_target($pdo,$viewer,$reportInput,$version);if(!$target)throw new RuntimeException('The referenced report version is unavailable.');
    if(!research_network_target_allowed_for_project($project,$target))throw new RuntimeException('This report cannot be referenced from this project. Non-public citations must remain inside the same Team, and a project cannot cite its own publication.');
    $note=mb_substr(trim($note),0,4000);$q=$pdo->prepare('SELECT public_id FROM research_project_report_references WHERE project_id=? AND target_report_id=? LIMIT 1');$q->execute([$project['id'],$target['report_id']]);$public=(string)($q->fetchColumn()?:'');
    if($public===''){$public=ulid_like();$pdo->prepare('INSERT INTO research_project_report_references(public_id,project_id,target_report_id,target_version_id,added_by_user_id,relation_type,note) VALUES(?,?,?,?,?,?,?)')->execute([$public,$project['id'],$target['report_id'],$target['version_id'],$viewer['id'],$relation,$note!==''?$note:null]);}
    else{$pdo->prepare('UPDATE research_project_report_references SET target_version_id=?,added_by_user_id=?,relation_type=?,note=?,updated_at=NOW() WHERE public_id=? AND project_id=?')->execute([$target['version_id'],$viewer['id'],$relation,$note!==''?$note:null,$public,$project['id']]);}
    return ['public_id'=>$public,'project_public_id'=>$project['public_id'],'target'=>$target,'relation_type'=>$relation,'note'=>$note];
}

function research_network_reference_delete(PDO $pdo,array $viewer,string $projectPublic,string $referencePublic): bool {
    $project=project_access($pdo,(int)$viewer['id'],trim($projectPublic));if(!$project||!project_can_write($project))throw new RuntimeException('You do not have permission to edit this Research project.');
    $q=$pdo->prepare('DELETE FROM research_project_report_references WHERE public_id=? AND project_id=?');$q->execute([trim($referencePublic),$project['id']]);return $q->rowCount()>0;
}

function research_network_project_references(PDO $pdo,array $viewer,string $projectPublic): array {
    if(!research_network_ready($pdo))return [];$project=project_access($pdo,(int)$viewer['id'],trim($projectPublic));if(!$project)return [];
    $q=$pdo->prepare('SELECT rpr.* FROM research_project_report_references rpr WHERE rpr.project_id=? ORDER BY rpr.updated_at DESC,rpr.id DESC');$q->execute([$project['id']]);$out=[];
    foreach($q->fetchAll() as $r){$target=research_network_version_meta_by_id($pdo,(int)$r['target_version_id'],$viewer);if(!$target){$out[]=['public_id'=>$r['public_id'],'available'=>false,'relation_type'=>$r['relation_type'],'note'=>$r['note'],'updated_at'=>$r['updated_at'],'target'=>null,'has_update'=>false,'publishable'=>['public'=>false,'team'=>false,'private'=>false]];continue;}
        $out[]=['public_id'=>$r['public_id'],'available'=>true,'relation_type'=>$r['relation_type'],'note'=>$r['note'],'updated_at'=>$r['updated_at'],'target'=>$target,'has_update'=>$target['current_version_number']>$target['version_number'],'publishable'=>[
          'public'=>research_network_publish_allowed($project,'public',$target),
          'team'=>!empty($project['team_id'])&&research_network_publish_allowed($project,'team',$target),
          'private'=>research_network_publish_allowed($project,'private',$target)
        ]];
    }return $out;
}

function research_network_snapshot_citations(PDO $pdo,array $project,string $sourceVisibility,array $publisher): array {
    if(!research_network_ready($pdo))return [];$refs=research_network_project_references($pdo,$publisher,(string)$project['public_id']);$out=[];
    foreach($refs as $r){if(!$r['available']||!$r['target']||!research_network_publish_allowed($project,$sourceVisibility,$r['target']))continue;$t=$r['target'];$out[]=[
      'report_id'=>$t['report_public_id'],'version_id'=>$t['version_public_id'],'version_number'=>$t['version_number'],'title'=>$t['version_title'],'snapshot_hash'=>$t['snapshot_hash'],
      'relation'=>$r['relation_type'],'note'=>$r['note'],'publisher'=>$t['publisher_name'],'published_at'=>$t['version_created_at']
    ];}
    return $out;
}

function research_network_index_version_citations(PDO $pdo,int $sourceVersionId,array $snapshot): int {
    if(!research_network_ready($pdo))return 0;$items=(array)($snapshot['report_citations']??[]);if(!$items)return 0;$q=$pdo->prepare('INSERT INTO research_report_version_citations(source_version_id,target_version_id,relation_type,note) VALUES(?,?,?,?)');$find=$pdo->prepare('SELECT id FROM research_report_versions WHERE public_id=? LIMIT 1');$count=0;
    foreach($items as $item){$find->execute([(string)($item['version_id']??'')]);$target=(int)($find->fetchColumn()?:0);if($target<=0||$target===$sourceVersionId)continue;$relation=(string)($item['relation']??'context');if(!isset(research_network_relation_types()[$relation]))$relation='context';$q->execute([$sourceVersionId,$target,$relation,isset($item['note'])&&trim((string)$item['note'])!==''?mb_substr(trim((string)$item['note']),0,4000):null]);$count++;}
    return $count;
}

function research_network_notify_citations(PDO $pdo,int $sourceVersionId,int $actorUserId): int {
    if(!research_network_ready($pdo))return 0;$q=$pdo->prepare("SELECT srv.version_number source_version_number,srr.public_id source_report_public_id,srr.title source_report_title,
      trr.public_id target_report_public_id,trr.created_by_user_id target_owner_user_id,trv.public_id target_version_public_id,trv.version_number target_version_number
      FROM research_report_version_citations c
      JOIN research_report_versions srv ON srv.id=c.source_version_id JOIN research_reports srr ON srr.id=srv.report_id
      JOIN research_report_versions trv ON trv.id=c.target_version_id JOIN research_reports trr ON trr.id=trv.report_id
      WHERE c.source_version_id=?");$q->execute([$sourceVersionId]);$count=0;
    foreach($q->fetchAll() as $r){$uid=(int)$r['target_owner_user_id'];$uq=$pdo->prepare("SELECT * FROM users WHERE id=? AND status='active' LIMIT 1");$uq->execute([$uid]);$targetOwner=$uq->fetch();if(!$targetOwner||!research_report_access($pdo,(string)$r['source_report_public_id'],$targetOwner))continue;
        $body='Your Research report was cited by '.$r['source_report_title'].' v'.$r['source_version_number'].'.';$ok=notification_create($pdo,$uid,$actorUserId,'research_report_cited','research_report',(string)$r['source_report_public_id'],$body,['category'=>'research','dedupe_key'=>'research-citation:'.$sourceVersionId.':'.$r['target_version_public_id'],'group_key'=>'research-citations:'.$r['target_report_public_id'],'context'=>['version_number'=>(int)$r['source_version_number'],'cited_report_public_id'=>$r['target_report_public_id'],'cited_version_public_id'=>$r['target_version_public_id'],'cited_version_number'=>(int)$r['target_version_number']]]);if($ok)$count++;}
    return $count;
}

function research_network_report_network(PDO $pdo,?array $viewer,array $report,int $versionNumber): array {
    if(!research_network_ready($pdo))return ['references'=>[],'cited_by'=>[],'version'=>null];$version=research_report_version_access($pdo,$report,$versionNumber,$viewer);if(!$version)return ['references'=>[],'cited_by'=>[],'version'=>null];$sourceId=(int)$version['id'];$outgoing=[];$q=$pdo->prepare('SELECT target_version_id,relation_type,note FROM research_report_version_citations WHERE source_version_id=? ORDER BY created_at,id');$q->execute([$sourceId]);
    foreach($q->fetchAll() as $r){$t=research_network_version_meta_by_id($pdo,(int)$r['target_version_id'],$viewer);if(!$t)continue;$outgoing[]=['relation_type'=>$r['relation_type'],'note'=>$r['note'],'target'=>$t,'has_update'=>$t['current_version_number']>$t['version_number']];}
    $incoming=[];$q=$pdo->prepare('SELECT source_version_id,relation_type,note FROM research_report_version_citations WHERE target_version_id=? ORDER BY created_at DESC,source_version_id DESC');$q->execute([$sourceId]);foreach($q->fetchAll() as $r){$s=research_network_version_meta_by_id($pdo,(int)$r['source_version_id'],$viewer);if(!$s)continue;$incoming[]=['relation_type'=>$r['relation_type'],'note'=>$r['note'],'source'=>$s];}
    return ['version'=>$version,'references'=>$outgoing,'cited_by'=>$incoming,'counts'=>['references'=>count($outgoing),'cited_by'=>count($incoming),'updates'=>count(array_filter($outgoing,fn($x)=>$x['has_update']))]];
}

function research_network_project_context(PDO $pdo,array $viewer,string $projectPublic,int $limit=12): array {
    $refs=array_slice(research_network_project_references($pdo,$viewer,$projectPublic),0,max(1,min(30,$limit)));$lines=['[REPORT CITATION NETWORK]'];$contextRefs=[];
    if(!$refs)$lines[]='No published Research reports are currently referenced by this project.';
    foreach($refs as $r){if(!$r['available']){$lines[]='Referenced report unavailable under current permissions.';continue;}$t=$r['target'];$line=$t['version_title'].' — pinned v'.$t['version_number'].' ('.$r['relation_type'].')';if($r['has_update'])$line.='; newer accessible version v'.$t['current_version_number'].' is available';$line.=' [REPORT '.$t['report_public_id'].' VERSION '.$t['version_public_id'].']';$lines[]=$line;$contextRefs[]=['type'=>'research_report','id'=>$t['report_public_id']];$contextRefs[]=['type'=>'research_report_version','id'=>$t['version_public_id']];}
    return ['text'=>implode("\n",$lines),'refs'=>$contextRefs,'references'=>$refs];
}

function research_network_cognitive_observations(PDO $pdo,array $viewer,array &$items,int $limitProjects=12): void {
    if(!research_network_ready($pdo))return;$q=$pdo->prepare("SELECT DISTINCT rp.public_id FROM research_projects rp LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=? WHERE rp.status='active' AND (rp.owner_user_id=? OR tm.user_id=?) ORDER BY rp.updated_at DESC LIMIT ".$limitProjects);$q->execute([$viewer['id'],$viewer['id'],$viewer['id']]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $projectPublic){foreach(research_network_project_references($pdo,$viewer,(string)$projectPublic) as $r){if(!$r['available']||!$r['has_update'])continue;$t=$r['target'];$actions=[cognitive_feed_action_link('Review citations','/research-citations.php?id='.rawurlencode((string)$projectPublic)),cognitive_feed_action_link('Compare report versions','/research-report-diff.php?id='.rawurlencode((string)$t['report_public_id']).'&from='.(int)$t['version_number'].'&to='.(int)$t['current_version_number']),cognitive_feed_action_agent('Ask Agent','Review the newer version of this cited Research report and explain whether the project should deliberately update its pinned citation. Do not change the citation automatically.',[['type'=>'research','public_id'=>(string)$projectPublic]])];
        cognitive_feed_add($items,['key'=>cognitive_feed_key('citation_update','research_report',(string)$r['public_id'],(string)$t['current_version_public_id']),'type'=>'citation_update','section'=>'new_evidence','priority'=>'medium','created_at'=>(string)($t['report']['updated_at']??$t['version_created_at']),'score_extra'=>7,'title'=>'Newer version of cited Research is available','body'=>$t['version_title'].' is pinned at v'.$t['version_number'].'; accessible v'.$t['current_version_number'].' is now available.','meta'=>['project'=>$projectPublic,'report'=>$t['report_public_id'],'pinned_version'=>$t['version_number'],'current_version'=>$t['current_version_number']],'actions'=>$actions]);}}
}
