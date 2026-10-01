<?php
declare(strict_types=1);
/** Independent Section 3 project workspace privacy and non-duplication review. */
$root=dirname(__DIR__);require_once $root.'/app/sponsored-project-builder.php';require_once $root.'/app/sponsored-project-workspace.php';
$fail=[];
$assert=static function(bool $condition,string $description)use(&$fail):void{
    if(!$condition)$fail[]=$description;
};
$reject=static function(callable $f,string $description)use(&$fail):void{
    try{$f();$fail[]=$description;}catch(InvalidArgumentException|RuntimeException $expected){}
};
$campaign=['public_id'=>'project-one','submission_deadline'=>'2030-12-15 20:00:00',
  'project_specs'=>['milestones'=>[['title'=>'Research plan','success_criteria'=>'Methods approved']]]];
$viewer=['id'=>22];$researcher=['role'=>'researcher','campaign'=>$campaign,
    'participation'=>['status'=>'active'],'assignment'=>['status'=>'active']];
$approved=sponsored_workspace_validate_update($researcher,$viewer,[
    'scope'=>'participant','progress_status'=>'started','milestone_position'=>'1',
    'title'=>'Work begun','body'=>'Researcher-owned update.'
]);
$assert($approved['scope']==='participant'&&$approved['participant_user_id']===22&&$approved['milestone_position']===1,
    'Researcher progress update is scoped to their own participant thread.');
$reject(static fn()=>sponsored_workspace_validate_update($researcher,$viewer,[
    'scope'=>'project','title'=>'Leaked broadcast','body'=>'Disallowed'
]),'Researchers cannot broadcast to other participants.');
$reject(static fn()=>sponsored_workspace_validate_update($researcher,$viewer,[
    'scope'=>'participant','progress_status'=>'completed','title'=>'Premature approval','body'=>'Disallowed'
]),'Researcher cannot mark the shared milestone completed.');
$reject(static fn()=>sponsored_workspace_validate_update(array_replace($researcher,['assignment'=>['status'=>'paused']]),$viewer,[
    'scope'=>'participant','title'=>'Paused agent','body'=>'Disallowed'
]),'Paused Agent assignments cannot write workspace updates.');
$reject(static fn()=>sponsored_workspace_validate_update($researcher,$viewer,[
    'scope'=>'participant','milestone_position'=>'2','title'=>'Foreign milestone','body'=>'Disallowed'
]),'Progress cannot reference a milestone not present in the configured project.');
$reject(static fn()=>sponsored_workspace_validate_update($researcher,$viewer,[
    'scope'=>'participant','title'=>'Overlong','body'=>str_repeat('X',4001)
]),'Oversized update bodies fail closed.');
$reject(static fn()=>sponsored_workspace_validate_update(['role'=>'sample','campaign'=>$campaign],$viewer,[
    'scope'=>'project','title'=>'Sample mutation','body'=>'Disallowed'
]),'Sample projects must never create real updates.');
$sponsor=['role'=>'sponsor','campaign'=>$campaign];
$participants=[
 ['public_id'=>'approved-member','user_id'=>22,'status'=>'active','assignment_status'=>'active'],
 ['public_id'=>'old-member','user_id'=>23,'status'=>'withdrawn','assignment_status'=>'removed']
];
$targeted=sponsored_workspace_validate_update($sponsor,['id'=>11],[
    'scope'=>'participant','participant_public_id'=>'approved-member','progress_status'=>'ready_for_review',
    'title'=>'Private sponsor response','body'=>'Only this researcher and sponsor.'
],$participants);
$assert($targeted['participant_user_id']===22,'Sponsor can address only an active assigned project participant.');
$reject(static fn()=>sponsored_workspace_validate_update($sponsor,['id'=>11],[
    'scope'=>'participant','participant_public_id'=>'old-member','title'=>'Removed member','body'=>'Disallowed'
],$participants),'Sponsor cannot send participant-thread updates to removed researchers.');
$broadcast=sponsored_workspace_validate_update($sponsor,['id'=>11],[
    'scope'=>'project','progress_status'=>'completed','title'=>'Milestone accepted','body'=>'Completed checkpoint.'
],$participants);
$assert($broadcast['participant_user_id']===null,'Project broadcast never embeds an individual participant target.');
$backend=(string)file_get_contents($root.'/app/sponsored-project-workspace.php');
$migration=(string)file_get_contents($root.'/database/migrations/20261001_129_sponsored_project_workspace.sql');
$assert(str_contains($backend,"up.scope='project' OR (up.scope='participant' AND up.participant_user_id=?)"),
    'Reader SQL restricts private participant updates to their own thread.');
$assert(str_contains($backend,'sponsored_workspace_access($pdo,$viewer,')&&str_contains($backend,'sponsored_workspace_validate_update($fresh'),
    'Mutations recheck current authority instead of trusting a stale browser session.');
$assert(str_contains($backend,'sponsored_research_participation_get(')&&str_contains($backend,'sponsored_project_assignment('),
    'Workspace researcher access requires the canonical accepted participation and assigned Agent.');
$detail=(string)file_get_contents($root.'/sponsored-project.php');
$workspacePage=(string)file_get_contents($root.'/sponsored-project-workspace.php');
$assert(str_contains($detail,'sponsored_workspace_access($pdo,$viewer')&&
        str_contains($detail,'sponsored_workspace_ready($pdo)'),
    'Project detail links to collaboration only after live capability and role checks.');
$assert(str_contains($workspacePage,'current_user($pdo)??[]')&&
        str_contains($workspacePage,'Sample-data preview: these posts are illustrative.'),
    'Public read-only demo can be previewed without creating real project data.');
$assert(str_contains($workspacePage,'research_agent_access($pdo,$viewer')&&
        str_contains($workspacePage,"research_agent_shell_href(\$agent,'desktop')")&&
        str_contains($workspacePage,"research_agent_shell_href(\$agent,'library')"),
    'Researcher uses their existing authorized Agent Desktop/Library without sharing private files with sponsor.');
$assert(str_contains($backend,"$"."assignment['participation_id']")&&
        str_contains($backend,"$"."participation['id']"),
    'Project access binds the exact accepted participation to the existing assignment.');
$assert(str_contains($backend,'A closed Sponsored Project workspace is read-only.'),
    'Terminal project workspaces cannot accept further journal writes.');
$closed=$sponsor;$closed['campaign']['status']='completed';
$reject(static fn()=>sponsored_workspace_validate_update($closed,['id'=>11],[
    'scope'=>'project','title'=>'After completion','body'=>'Must not write'
]),'Completed campaigns are read-only in the collaboration layer.');
$assert(!str_contains($backend,'research_agent_workspace_create_')&&!str_contains($backend,'team_research_assign('),
    'Project updates never bypass canonical Team/Agent ACL or duplicate desktop writes.');

$shared=sponsored_workspace_milestone_states(
    [['title'=>'Planning','success_criteria'=>'Document sources']],
    [
        ['scope'=>'participant','actor_role'=>'researcher','milestone_position'=>1,'progress_status'=>'completed'],
        ['scope'=>'project','actor_role'=>'sponsor','milestone_position'=>1,'progress_status'=>'ready_for_review']
    ]
);
$assert($shared===['ready_for_review'],'Private researcher status cannot be mistaken for shared sponsor milestone completion.');
$planned=sponsored_workspace_milestone_states([['title'=>'Planning']],[
    ['scope'=>'participant','actor_role'=>'researcher','milestone_position'=>1,'progress_status'=>'blocked']
]);
$assert($planned===['planned'],'Unpublished private thread notes cannot change public milestone projection.');
$assert(str_contains($workspacePage,'sponsored_workspace_milestone_states(')
  &&str_contains($workspacePage,'sponsoredWorkspaceMilestoneState'),
    'Milestone rail shows scoped journal progress without mutating canonical task states.');
$assert(str_contains($migration,'CREATE TABLE IF NOT EXISTS sponsored_project_updates')
    &&!str_contains($backend,'UPDATE sponsored_project_updates')
    &&!str_contains($backend,'DELETE FROM sponsored_project_updates'),
    'Contributor progress uses a distinct append-only ledger, not a duplicate task or document engine.');
if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Independent Section 3 project workspace privacy and provenance contracts passed.\n";
