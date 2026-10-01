<?php
declare(strict_types=1);
/**
 * Phase 80 Section 4A — canonical Sponsored Research Agent integration map.
 * This is an audited dependency/permission contract, not a second Agent runtime.
 * Sections 4B–4E implement only the explicitly missing adapters below.
 */
function sponsored_agent_integration_map(): array {
    return [
      'scope'=>'Sponsored Research / assigned researcher and Agent only',
      'ownership'=>'Personally owned active Research Agent with matching accepted assignment; do not grant sponsor or Team access to private Agent files',
      'dependencies'=>[
        'project_authority'=>['function'=>'sponsored_workspace_access','file'=>'app/sponsored-project-workspace.php','state'=>'existing'],
        'approved_researcher'=>['function'=>'research_account_require_approved','file'=>'app/research-accounts.php','state'=>'existing'],
        'accepted_terms'=>['function'=>'sponsored_research_participation_get','file'=>'app/sponsored-research-participation.php','state'=>'existing'],
        'assigned_agent'=>['function'=>'sponsored_project_assignment','file'=>'app/sponsored-research-projects.php','state'=>'existing'],
        'agent_context'=>['function'=>'sponsored_project_agent_context','file'=>'app/sponsored-research-projects.php','state'=>'existing'],
        'project_specs'=>['function'=>'sponsored_project_builder_normalize','file'=>'app/sponsored-project-builder.php','state'=>'existing'],
        'private_agent_acl'=>['function'=>'research_agent_access','file'=>'app/research-agents.php','state'=>'existing'],
        'research_tasks'=>['function'=>'research_task_create','file'=>'app/research-tasks.php','state'=>'existing'],
        'chat_context'=>['function'=>'agent_chat_context_item','file'=>'app/agent-chat.php','state'=>'existing'],
        'action_proposal'=>['function'=>'agent_action_create_proposals','file'=>'app/agent-actions.php','state'=>'existing'],
        'action_confirmation'=>['function'=>'agent_action_confirm_execute','file'=>'app/agent-actions.php','state'=>'existing'],
        'automation'=>['function'=>'research_automation_execute','file'=>'app/research-automation.php','state'=>'existing'],
        'submission'=>['function'=>'sponsored_project_submit','file'=>'app/sponsored-research-projects.php','state'=>'existing'],
        'workspace_updates'=>['function'=>'sponsored_workspace_post','file'=>'app/sponsored-project-workspace.php','state'=>'existing'],
        'notification_create'=>['function'=>'notification_create','file'=>'app/notifications.php','state'=>'existing'],
        'notification_acl'=>['function'=>'notification_object_access','file'=>'app/notifications.php','state'=>'existing'],
      ],
      'gaps'=>[
        '4b_context'=>'No selected sponsored_project context type in Agent Chat and no complete per-assignment terms/specs/milestone/authorized-thread read model.',
        '4c_actions'=>'No Sponsored Project-scoped confirmation capability or explicit submission authorization in the existing Agent Chat action registry.',
        '4d_proactive'=>'No deadline/blocker/revision notifier tied to exact assignment and accepted revision with deduped, permission-gated notification URL.',
        '4e_acceptance'=>'No consolidated Sponsor→approved Researcher→assigned Agent→Chat proposals→explicit approval→submission→review E2E gate.',
      ],
      'invariants'=>[
        'No implicit Agent, Team, sponsor, or commercial-account permission escalation.',
        'Project context requires exact active/completed accepted participation and same assigned Agent; closed/paused assignments are read-only.',
        'No cross-researcher messages, private Agent files, unconsented data reuse, or training-right grants.',
        'Agent may prepare drafts and propose scoped work; any consequential write follows user confirmation and current-state revalidation.',
        'Submission remains governed by sponsored_project_submit, original terms acceptance, source snapshots, revision lineage and sponsor review.',
        'No duplicate task, Agent, automation, notification, document, submission, or compensation store.',
        'Sample projects may display read-only examples but never execute, propose payment or create persistent actions.',
      ],
    ];
}
function sponsored_agent_integration_dependencies_present(string $repositoryRoot): array {
    $map=sponsored_agent_integration_map();$missing=[];
    foreach($map['dependencies'] as $name=>$dep){
        $path=$repositoryRoot.'/'.$dep['file'];
        if(!is_file($path)||!str_contains((string)file_get_contents($path),'function '.$dep['function'].'('))
            $missing[]=$name;
    }
    return $missing;
}
