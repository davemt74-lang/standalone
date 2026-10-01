<?php
declare(strict_types=1);
/** Independent Section 2 review: strict specs, privacy and demo parity. */
$root=dirname(__DIR__);
require_once $root.'/app/sponsored-project-builder.php';
$fail=[];
$expect=static function(bool $ok,string $message)use(&$fail):void{
    if(!$ok)$fail[]=$message;
};
$specs=sponsored_project_builder_normalize([
  'target_audience'=>'Independent retail owners','geography'=>'Arizona',
  'scope_in'=>'Operations and customer service','scope_out'=>'Personal medical records',
  'methods'=>['Desk research','Interviews'],
  'deliverables'=>[['title'=>'Market report','format'=>'report','acceptance_criteria'=>'Ten cited sources','due_date'=>'2030-12-01']],
  'milestones'=>[
    ['title'=>'Plan','due_date'=>'2030-11-10','success_criteria'=>'Methods approved'],
    ['title'=>'Interview guide','due_date'=>'2030-11-10','success_criteria'=>'Guide approved'],
    ['title'=>'Synthesis','due_date'=>'2030-11-20','success_criteria'=>'Findings documented']
  ]
],'2030-12-15 00:00:00');
$expect(count($specs['milestones'])===3,'Same-day milestones must remain supported.');
$expect(count($specs['deliverables'])===1&&$specs['deliverables'][0]['acceptance_criteria']==='Ten cited sources','Deliverable criteria must survive validation.');
$expect(sponsored_project_builder_normalize($specs,'2030-12-15 00:00:00')===$specs,'Structured specifications must round-trip without mutation.');
$roundTrip=sponsored_project_builder_normalize([
  'deliverables'=>sponsored_project_builder_lines_export($specs['deliverables'],'deliverable'),
  'milestones'=>sponsored_project_builder_lines_export($specs['milestones'],'milestone'),
],'2030-12-15 00:00:00');
$expect($roundTrip['deliverables']===$specs['deliverables']&&$roundTrip['milestones']===$specs['milestones'],'Textarea export and re-import must preserve criteria and dates.');
foreach([
  ['methods'=>['Invalid unsafe custom method']],
  ['deliverables'=>[['title'=>'Underspecified','format'=>'report']]],
  ['deliverables'=>[['title'=>'Invalid format','format'=>'executable','acceptance_criteria'=>'Test']]],
  ['deliverables'=>[['title'=>'Late','format'=>'report','acceptance_criteria'=>'Late','due_date'=>'2031-01-01']]],
  ['milestones'=>[['title'=>'Late','success_criteria'=>'Late','due_date'=>'2031-01-01']]],
  ['milestones'=>[['title'=>'Later','success_criteria'=>'Accepted','due_date'=>'2030-12-01'],['title'=>'Earlier','success_criteria'=>'Accepted','due_date'=>'2030-11-01']]],
  ['scope_in'=>str_repeat('X',3001)]
] as $bad){
    try{sponsored_project_builder_normalize($bad,'2030-12-15 00:00:00');$expect(false,'Invalid specification input was accepted.');}
    catch(InvalidArgumentException $e){}
}
$ui=(string)file_get_contents($root.'/app/sponsored-project-builder-ui.php');
$detail=(string)file_get_contents($root.'/sponsored-project.php');
$model=(string)file_get_contents($root.'/app/sponsored-project-detail.php');
$campaign=(string)file_get_contents($root.'/app/sponsored-research-campaigns.php');
$sponsor=(string)file_get_contents($root.'/sponsored-research.php');
$public=(string)file_get_contents($root.'/app/sponsored-research-project-compensation.php');
$expect(str_contains($ui,'h((string)$x)'),'All sponsor-controlled form values must be output-escaped.');
$expect(str_contains($detail,"$"."p['project_specs']")&&str_contains($detail,'sponsoredDetailMilestones'),'Dedicated project page must display detailed specification and milestone content.');
$expect(str_contains($model,'sponsored_project_detail_sample_builder_specs'),'Samples use the same project-detail specification shape.');
$expect(str_contains($sponsor,'sponsored_project_builder_from_post($_POST)'),'Campaign create/update must pass sanitized specification data.');
$expect(str_contains($campaign,"'project_specs'=>")&&str_contains($campaign,'sponsored_research_campaign_snapshot('),'Project specifications must use canonical immutable campaign revisions.');
$expect(str_contains($public,'c.project_specs_json'),'Strict operational public getter must select project specs without exposing private campaign.');
$migration=(string)file_get_contents($root.'/database/migrations/20261001_128_sponsored_project_builder.sql');
$expect(str_contains($migration,'ADD COLUMN project_specs_json'),'Migration must extend existing sponsored campaign, never add second project engine.');
if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Section 2 independent Project Builder review contracts passed.\n";
