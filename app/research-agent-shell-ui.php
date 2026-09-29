<?php
declare(strict_types=1);

function research_agent_shell_tabs(): array {
    return [
        'chat'=>['label'=>'Chat'],
        'knowledge'=>['label'=>'Knowledge'],
        'research'=>['label'=>'Research'],
        'reports'=>['label'=>'Reports'],
    ];
}

function research_agent_shell_agents(PDO $pdo,array $viewer,int $limit=50): array {
    if(function_exists('research_agent_ensure_default'))research_agent_ensure_default($pdo,$viewer);
    return function_exists('research_agent_list')?research_agent_list($pdo,$viewer,$limit):[];
}

function research_agent_shell_resolve(PDO $pdo,array $viewer,string $candidate='',?array $agents=null): array {
    $agents=$agents??research_agent_shell_agents($pdo,$viewer,50);
    $candidate=trim($candidate);$selected=null;
    if($candidate!==''){
        foreach($agents as $agent){
            if(hash_equals((string)($agent['public_id']??''),$candidate)||hash_equals((string)($agent['conversation_public_id']??''),$candidate)){
                $selected=$agent;break;
            }
        }
        if(!$selected&&function_exists('research_agent_access'))$selected=research_agent_access($pdo,$viewer,$candidate);
        if(!$selected&&function_exists('research_agent_by_conversation'))$selected=research_agent_by_conversation($pdo,$viewer,$candidate);
    }
    if(!$selected&&$agents)$selected=$agents[0];
    return ['agents'=>$agents,'agent'=>$selected,'agent_id'=>(string)($selected['public_id']??'')];
}

function research_agent_shell_href(array $agent,string $tab): string {
    $tab=strtolower(trim($tab));$agentPublic=(string)($agent['public_id']??'');$conversation=(string)($agent['conversation_public_id']??'');
    return match($tab){
        'chat'=>$conversation!==''?'/home.php?agent='.rawurlencode($conversation):'/research.php',
        'knowledge'=>$agentPublic!==''?'/research-agent-knowledge.php?agent='.rawurlencode($agentPublic):'/research.php',
        'research'=>$agentPublic!==''?'/research-agent-research.php?agent='.rawurlencode($agentPublic):'/research.php',
        'reports'=>$agentPublic!==''?'/research-reports.php?agent='.rawurlencode($agentPublic):'/research.php',
        'library'=>$conversation!==''?'/home.php?agent='.rawurlencode($conversation).'&workspace=library':'/research.php',
        'desktop'=>$conversation!==''?'/home.php?agent='.rawurlencode($conversation).'&workspace=desktop':'/research.php',
        default=>'/research.php',
    };
}

function research_agent_shell_render(?array $agent,array $agents,string $active='chat',array $options=[]): string {
    if(!$agent)return '';
    $active=array_key_exists($active,research_agent_shell_tabs())?$active:'chat';
    $showWorkspace=!array_key_exists('workspace_controls',$options)||!empty($options['workspace_controls']);
    $showClose=!empty($options['close']);
    ob_start();?>
    <section class="researchAgentUnifiedShell" data-research-agent-shell data-agent-id="<?=h((string)$agent['public_id'])?>" data-agent-conversation="<?=h((string)$agent['conversation_public_id'])?>" data-active-tab="<?=h($active)?>">
      <div class="researchAgentUnifiedIdentity">
        <a class="researchAgentUnifiedBack" href="/research.php" aria-label="Back to Research">←</a>
        <div><span>RESEARCH AGENT</span><strong><?=h((string)$agent['name'])?></strong></div>
      </div>
      <nav class="researchAgentUnifiedTabs" aria-label="Research Agent workspace">
        <?php foreach(research_agent_shell_tabs() as $key=>$tab):?><a href="<?=h(research_agent_shell_href($agent,$key))?>" class="<?=$active===$key?'active':''?>" <?=$active===$key?'aria-current="page"':''?> data-research-agent-tab="<?=h($key)?>"><?=h($tab['label'])?></a><?php endforeach?>
      </nav>
      <div class="researchAgentUnifiedTools">
        <?php if($showWorkspace):?><a href="<?=h(research_agent_shell_href($agent,'library'))?>" data-research-agent-workspace-link="library">Library</a><a href="<?=h(research_agent_shell_href($agent,'desktop'))?>" data-research-agent-workspace-link="desktop">Desktop</a><?php endif?>
        <?php if(count($agents)>1):?><label class="researchAgentUnifiedSwitcher"><span class="srOnly">Research Agent</span><select data-research-agent-shell-switch data-shell-tab="<?=h($active)?>" aria-label="Switch Research Agent"><?php foreach($agents as $row):?><option value="<?=h((string)$row['public_id'])?>" data-conversation="<?=h((string)$row['conversation_public_id'])?>" <?=hash_equals((string)$agent['public_id'],(string)$row['public_id'])?'selected':''?>><?=h((string)$row['name'])?></option><?php endforeach?></select></label><?php endif?>
        <?php if($showClose):?><button type="button" class="agentChatPanelClose researchAgentUnifiedClose" data-agent-panel-close aria-label="Close Research Agent">×</button><?php endif?>
      </div>
    </section>
    <?php return (string)ob_get_clean();
}
