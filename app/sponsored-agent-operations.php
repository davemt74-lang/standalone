<?php
declare(strict_types=1);
/**
 * Sponsored Research 4C: Agent-guided handoffs to existing confirmed Research
 * tasks, reports and native manual Sponsor submission. No duplicate action
 * executor, scheduler, payment workflow or nested database transactions.
 */
function sponsored_agent_operations_catalog(): array {
    return [
      'plan'=>['title'=>'Plan the research','description'=>'Review accepted objectives, deliverables and current research; propose a plan that requires your confirmation.',
        'prompt'=>'Review only my accepted Sponsored Project brief, objectives, milestones and deliverables. Propose a practical Research Plan with evidence criteria, dependencies and deadlines for my personally assigned Research Agent. Use existing confirmed Research actions only; do not create or start anything unless I confirm.'],
      'tasks'=>['title'=>'Prepare Research Tasks','description'=>'Identify missing evidence and propose bounded Research Tasks in the assigned Agent workspace.',
        'prompt'=>'Compare my accepted Sponsored Project requirements against my existing Research project. Identify evidence gaps and propose only useful Research Tasks in that existing project. Every proposed task must require my explicit confirmation. Do not alter a sponsor milestone or participant agreement.'],
      'report'=>['title'=>'Prepare an Agent Report','description'=>'Review available evidence and prepare a confirmed Report Run or editable document in the existing Agent workspace.',
        'prompt'=>'Assess whether my assigned Research Agent has enough documented evidence for the accepted Sponsored Project deliverables. Identify gaps and propose an existing Research Report Run or editable Research Document only with my confirmation. Do not submit or publish it automatically.'],
      'milestones'=>['title'=>'Review milestones and blockers','description'=>'Read the current timeline and permitted updates; identify next steps without silently changing project status.',
        'prompt'=>'Summarize my accepted Sponsored Project milestones, deadlines and the project updates I am permitted to see. Identify approaching dates, unresolved blockers and actionable next steps. Sponsor-owned shared milestone status is read-only here. Do not create updates, claim completion or contact the sponsor.'],
      'update'=>['title'=>'Draft a private progress update','description'=>'Draft a researcher-to-sponsor update; you decide what to publish through your existing workspace form.',
        'prompt'=>'Draft a concise private progress update for the sponsor of my accepted Sponsored Project, citing my own verified work and any blocker. Provide a suggested title, body, status and milestone reference. Do not post it; I will review and explicitly publish it in the existing project workspace.'],
      'submit'=>['title'=>'Review submission readiness','description'=>'Verify the current accepted terms and evidence; explicitly submit through the existing Sponsored Research form yourself.',
        'prompt'=>'Review my current Sponsored Project submission readiness using only my accepted terms, own Agent evidence, existing Report Runs, any revision request and current assignment status. Give me a checklist of remaining work and identify suitable ready reports or published versions. Do NOT submit, change terms, publish or request payment; I will explicitly choose and submit the final artifact using the existing Sponsored Research form.'],
    ];
}
function sponsored_agent_operations_handoff(PDO $pdo,array $viewer,string $campaignPublic,string $agentConversation,string $operation): ?array {
    $options=sponsored_agent_operations_catalog();
    if(!isset($options[$operation])||$campaignPublic===''||$agentConversation===''||
       !function_exists('research_agent_by_conversation')||
       !function_exists('sponsored_agent_awareness_access'))return null;
    $agent=research_agent_by_conversation($pdo,$viewer,$agentConversation);
    if(!$agent||!hash_equals((string)$agent['conversation_public_id'],$agentConversation))return null;
    // Never hand off paid-project context into a shared or subsequently invited
    // conversation. Validate the current conversation at every deep link.
    if(!function_exists('sponsored_agent_awareness_private_conversation'))return null;
    $q=$pdo->prepare('SELECT * FROM conversations WHERE public_id=? LIMIT 1');
    $q->execute([$agentConversation]);$conversation=$q->fetch();
    if(!$conversation||!sponsored_agent_awareness_private_conversation($pdo,$viewer,$conversation))return null;
    $access=sponsored_agent_awareness_access($pdo,$viewer,$campaignPublic,(string)$agent['public_id']);
    if(!$access)return null;
    // Manual project operations are available only while both the sponsor
    // project and the researcher's own accepted assignment can be worked on.
    if(($access['assignment']['status']??'')!=='active'||
       ($access['participation']['status']??'')!=='active'||
       !in_array((string)($access['campaign']['status']??''),['open','scheduled'],true))return null;
    $context=sponsored_agent_awareness_project_context($pdo,$viewer,$campaignPublic,(string)$agent['public_id']);
    if(!$context||($context['meta']['requires_reacceptance']??true))return null;
    return [
      'prompt'=>$options[$operation]['prompt'],
      'context'=>[['type'=>'sponsored_project','public_id'=>$campaignPublic,'label'=>mb_substr((string)$access['campaign']['title'],0,90)]],
      'conversation'=>$agentConversation,
      'research_agent'=>true,
      'source'=>'sponsored_project_guided_operation',
    ];
}
function sponsored_agent_operations_link(array $agent,string $campaignPublic,string $operation): string {
    if(!isset(sponsored_agent_operations_catalog()[$operation]))throw new InvalidArgumentException('Unknown Sponsored Agent operation.');
    $conversation=(string)($agent['conversation_public_id']??'');
    if($conversation==='')throw new InvalidArgumentException('An existing personal Agent conversation is required.');
    return '/home.php?agent='.rawurlencode($conversation).'&sponsored_project='.rawurlencode($campaignPublic).'&sponsored_action='.rawurlencode($operation);
}
