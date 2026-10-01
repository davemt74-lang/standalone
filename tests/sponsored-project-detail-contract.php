<?php
declare(strict_types=1);
/** Pure runtime access matrix and navigation contract for the canonical project page. */
$root=dirname(__DIR__);$fails=[];
function projectDetailCheck(bool $value,string $reason):void{global $fails;if(!$value)$fails[]=$reason;else echo "PASS: $reason\n";}
function sponsored_research_campaign_eligibility(mixed $raw):array {
    return array_merge(['min_verification'=>'basic','specialties'=>[],'languages'=>[],'min_completed_campaigns'=>0],(array)$raw);
}
function sponsored_research_campaign_disclosures(mixed $raw):array {
    return array_merge(['sponsorship_disclosure_required'=>true,'conflict_disclosure_required'=>true,'nda_required'=>false,'ai_assistance_policy'=>'allowed_with_disclosure','training_use_request'=>'none'],(array)$raw);
}
function sponsored_project_sample_projects(PDO $pdo):array {
    return empty($GLOBALS['sample_enabled'])?[]:[[
      'public_id'=>'sample-market-ai-001','title'=>'Example Retail','brief'=>'Example brief','status'=>'open',
      'access_mode'=>'public','organization_name'=>'Demo Org','sample_data'=>1,
      'budget_currency'=>'USD','researcher_compensation_cents'=>45000,'requirements'=>['Identity verification']
    ]];
}
function sponsored_research_campaigns_ready(PDO $pdo):bool{return true;}
function sponsored_research_campaign_by_public(PDO $pdo,string $id):?array {
    return $GLOBALS['real_projects'][$id]??null;
}
function sponsored_research_campaign_require_manage(PDO $pdo,array $viewer,string $id):array {
    $p=sponsored_research_campaign_by_public($pdo,$id);
    if(!$p||($viewer['role']??'')!=='owner')throw new RuntimeException('Not allowed');
    return $p;
}
function research_account_is_approved(PDO $pdo,array $viewer):bool{return !empty($viewer['approved']);}
function sponsored_research_campaign_visible_to_researcher(PDO $pdo,array $viewer,array $p):bool {
    return $p['status']==='open'&&($p['access_mode']==='public'||!empty($viewer['invited']));
}
function sponsored_research_campaign_eligibility_check(PDO $pdo,array $viewer,array $p):array {
    return ['eligible'=>!empty($viewer['approved']),'reasons'=>[]];
}
function sponsored_project_public_get(PDO $pdo,string $id):?array {
    $p=$GLOBALS['real_projects'][$id]??null;
    return $p&&$p['status']==='open'&&$p['access_mode']==='public'?$p:null;
}
require_once $root.'/app/sponsored-project-detail.php';
$pdo=(new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
$GLOBALS['sample_enabled']=true;
$GLOBALS['real_projects']=[
    'public-id'=>[
      'public_id'=>'public-id','title'=>'Public Real','organization_name'=>'Real Sponsor',
      'brief'=>'Real brief','objective'=>'Real research objective','status'=>'open','access_mode'=>'public',
      'budget_currency'=>'USD','researcher_compensation_cents'=>25000,
      'eligibility'=>['min_verification'=>'identity'],
      'disclosures'=>['sponsorship_disclosure_required'=>false,'training_use_request'=>'none'],
      'questions'=>[['question'=>'Real question?']]
    ],
    'private-id'=>[
      'public_id'=>'private-id','title'=>'Private Real','organization_name'=>'Real Sponsor',
      'brief'=>'Confidential brief','objective'=>'Confidential objective','status'=>'open','access_mode'=>'private',
      'budget_currency'=>'USD','researcher_compensation_cents'=>30000,'questions'=>[['question'=>'Private question?']]
    ]
];
$sample=sponsored_project_detail_resolve($pdo,null,'sample-market-ai-001');
projectDetailCheck($sample!==null&&$sample['role']==='sample'&&$sample['project']['sample']===true,'Enabled sample has a dedicated read-only project model');
projectDetailCheck(count($sample['project']['questions'])===3&&count($sample['project']['sample_deliverables'])===3,'Canonical samples gain meaningful detailed specifications');
$GLOBALS['sample_enabled']=false;
projectDetailCheck(sponsored_project_detail_resolve($pdo,null,'sample-market-ai-001')===null,'Admin OFF hides sample detail URLs as well as list cards');
$GLOBALS['sample_enabled']=true;
projectDetailCheck(sponsored_project_detail_resolve($pdo,null,'private-id')===null,'Guests cannot retrieve private real campaign details');
projectDetailCheck(sponsored_project_detail_resolve($pdo,['role'=>'other'],'private-id')===null,'Unapproved outsiders cannot retrieve private campaigns');
$guest=sponsored_project_detail_resolve($pdo,null,'public-id');
projectDetailCheck($guest!==null&&$guest['role']==='visitor'&&$guest['project']['objective']==='Real research objective','Public live campaigns resolve to the same detailed layout');
projectDetailCheck($guest['project']['disclosures']['sponsorship_disclosure_required']===false,'Published campaign disclosure configuration is preserved');
$researcher=sponsored_project_detail_resolve($pdo,['role'=>'researcher','approved'=>true,'invited'=>true],'private-id');
projectDetailCheck($researcher!==null&&$researcher['role']==='researcher'&&!empty($researcher['eligibility']['eligible']),'Invited approved researcher sees private opportunity through existing access policy');
$owner=sponsored_project_detail_resolve($pdo,['role'=>'owner'],'private-id');
projectDetailCheck($owner!==null&&$owner['role']==='sponsor','Authorized sponsor can view non-public own project');
projectDetailCheck(sponsored_project_detail_resolve($pdo,null,'missing-id')===null,'Unknown projects do not render');
$detail=(string)file_get_contents($root.'/sponsored-project.php');
$public=(string)file_get_contents($root.'/research-projects.php');
$sponsor=(string)file_get_contents($root.'/sponsored-research.php');
$researcherPage=(string)file_get_contents($root.'/research-sponsored-projects.php');
$runtime=(string)file_get_contents($root.'/app/sponsored-research-project-compensation.php');
projectDetailCheck(str_contains($detail,'sponsored_project_detail_resolve(')&&str_contains($detail,'$role===\'sponsor\'')&&str_contains($detail,'$role===\'researcher\''),'Project page exposes role-based actions');
projectDetailCheck(str_contains($detail,'DEMONSTRATION · SAMPLE DATA')&&str_contains($detail,'Preview only.'),'Sample actions clearly disabled on detail page');
projectDetailCheck(!str_contains($detail,"REQUEST_METHOD")&&!str_contains($detail,'sponsored_project_assign_agent('),'Public project page never mutates assignments or payments');
projectDetailCheck(str_contains($public,'Location: /sponsored-project.php?project=')&&str_contains($public,'View sample project'),'Public discovery links and legacy redirects reach canonical detail');
projectDetailCheck(str_contains($sponsor,'View project page')&&str_contains($researcherPage,'Project specifications'),'Sponsor and researcher flows link to project detail');
projectDetailCheck(str_contains($runtime,'c.disclosure_json,c.created_at')&&str_contains($runtime,"\$row['disclosures']="),'Public project getter supplies accurate published disclosure choices');
projectDetailCheck(is_file($root.'/assets/css/sponsored-project-detail.css'),'Responsive project-specific styles exist');
if($fails){foreach($fails as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Sponsored Project detail access, visibility and navigation contract passed.\n";
