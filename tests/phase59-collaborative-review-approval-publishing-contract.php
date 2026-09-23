<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail){if(!is_file($root.'/'.$file)){$fail[]="$label file missing: $file";return;}$body=(string)file_get_contents($root.'/'.$file);foreach($needles as $needle)if(!str_contains($body,$needle))$fail[]="$label contract missing in $file: $needle";};
$must('database/migrations/20260923_057_collaborative_review_approval_publishing.sql',[
 "subject_type ENUM('claim','finding','report_version','agent_action','document')",'reviewer_role','is_required','research_publication_workflows','research_publication_review_rounds','research_review_threads','research_review_thread_messages','research_publication_approval_gates','research_publication_distribution_targets','research_publication_distribution_events','research_publication_events','publication_workflow_id','document_revision_public_id','approval_snapshot_json'
],'Phase 59 migration');
$must('app/research-publishing.php',[
 'research_publication_workflow_create','research_publication_request_review','research_publication_restart_review','research_publication_thread_create','research_publication_thread_resolve','research_publication_required_approvals','research_publication_evaluate','research_publication_owner_approve','research_publication_document_changed','research_publication_document_snapshot','research_publication_publish','research_publication_distribute','research_publication_cognitive_observations'
],'Phase 59 runtime');
$must('app/research-reviews.php',['research_review_assignment_roles_ready',"if(\$type==='document')",'reviewer_role','research_publication_sync_review'],'Phase 59 Review Center extension');
$must('app/research-reports.php',['?array $documentSnapshot=null','?array $publicationMeta=null','publication_workflow_id','approval_snapshot_json'],'Phase 59 immutable report extension');
$must('api/research-publications.php',['request_review','restart_review','owner_approve','thread_create','thread_resolve','publish'],'Phase 59 API');
$must('research-publications.php',['PHASE 59 · REVIEW, APPROVAL & PUBLISHING','Required approver','Review threads','Owner approval','Publish immutable version','Specific recipients'],'Phase 59 Publishing Center');
$must('research-reviews.php',['Anchored evidence threads','reviewer_role','required','publicationWorkflow'],'Phase 59 Review Center UI');
$must('app/agent-actions.php',["'research.prepare_publication_review'","'research.publish_approved_document'","research_publication_request_review","research_publication_publish"],'Phase 59 governed Agent action');
$must('home.php',['data-research-library-doc-publish','data-research-document-publish','app.css?v=59.0','research-agent-workspace-ui.js?v=59.0'],'Phase 59 document launch');
$must('assets/js/research-agent-workspace-ui.js',['publishLibraryDocument','publishDesktopDocument',"'/research-publications.php?doc='"],'Phase 59 document JS');
$must('app/cognitive-feed.php',['research_publication_cognitive_observations'],'Phase 59 Now integration');
$must('app/research-agent-workspace.php',['research_publication_document_changed'],'Phase 59 document revision invalidation');
$css=(string)file_get_contents($root.'/assets/css/app.css');$ext=(string)file_get_contents($root.'/extension/landing-app.css');if(!hash_equals(hash('sha256',$css),hash('sha256',$ext)))$fail[]='Website and extension landing CSS must remain byte-identical.';foreach(['.publicationCenter','.publicationGateGrid','.publicationThread','.publicationRecipients'] as $needle)if(!str_contains($css,$needle))$fail[]="Phase 59 CSS contract missing: $needle";
if($fail){fwrite(STDERR,implode("\n",$fail)."\n");exit(1);}echo "Phase 59 Collaborative Review, Approval & Publishing static contracts passed.\n";
