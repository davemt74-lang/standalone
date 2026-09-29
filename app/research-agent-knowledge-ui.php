<?php
declare(strict_types=1);

function research_agent_knowledge_views(): array {
    return [
        'library'=>[
            'label'=>'Library',
            'description'=>'Sources, files, annotations, captured evidence, and retrieval-ready knowledge.',
        ],
        'insights'=>[
            'label'=>'Insights',
            'description'=>'Claims, Findings, Entities, verification, provenance, and source intelligence.',
        ],
        'changes'=>[
            'label'=>'Changes',
            'description'=>'Monitoring, source changes, longitudinal evolution, and confidence movement.',
        ],
    ];
}

function research_agent_knowledge_view(string $candidate): string {
    $candidate=strtolower(trim($candidate));
    return array_key_exists($candidate,research_agent_knowledge_views())?$candidate:'library';
}

function research_agent_knowledge_href(string $agentPublic,string $view='library',array $query=[]): string {
    $view=research_agent_knowledge_view($view);
    $params=array_merge(['agent'=>trim($agentPublic),'view'=>$view],$query);
    $params=array_filter($params,static fn($value)=>$value!==''&&$value!==null);
    return '/research-agent-knowledge.php?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
}

function research_agent_knowledge_engine_links(array $agent): array {
    $agentPublic=trim((string)($agent['public_id']??''));
    $conversation=trim((string)($agent['conversation_public_id']??''));
    $project=trim((string)($agent['project_public_id']??''));
    $agentQ=rawurlencode($agentPublic);
    $projectQ=rawurlencode($project);
    $conversationQ=rawurlencode($conversation);
    return [
        'library'=>[
            ['label'=>'Open Library','href'=>$conversation!==''?'/home.php?agent='.$conversationQ.'&workspace=library':''],
            ['label'=>'Open Desktop','href'=>$conversation!==''?'/home.php?agent='.$conversationQ.'&workspace=desktop':''],
            ['label'=>'VP3 Library','href'=>'/vp3-library.php'],
        ],
        'insights'=>[
            ['label'=>'Verification','href'=>$project!==''?'/research-verification.php?id='.$projectQ:''],
            ['label'=>'Provenance','href'=>$project!==''?'/research-provenance.php?id='.$projectQ:''],
            ['label'=>'Evidence Packs','href'=>$project!==''?'/research-evidence-packs.php?id='.$projectQ:''],
            ['label'=>'Citations','href'=>$project!==''?'/research-citations.php?id='.$projectQ:''],
            ['label'=>'Entity Graph','href'=>$project!==''?'/research-entities.php?id='.$projectQ:''],
            ['label'=>'Claim Graph','href'=>$project!==''?'/research-graph.php?id='.$projectQ:''],
        ],
        'changes'=>[
            ['label'=>'Manage Monitoring','href'=>$agentPublic!==''?'/research-monitoring.php?agent='.$agentQ:''],
            ['label'=>'Evolution History','href'=>$agentPublic!==''?'/research-evolution.php?agent='.$agentQ:''],
        ],
    ];
}

function research_agent_knowledge_render_nav(string $agentPublic,string $active='library'): string {
    $active=research_agent_knowledge_view($active);
    ob_start();?>
    <nav class="researchKnowledgeUnifiedNav" aria-label="Knowledge views">
      <?php foreach(research_agent_knowledge_views() as $key=>$view):?>
        <a href="<?=h(research_agent_knowledge_href($agentPublic,$key))?>" class="<?=$active===$key?'active':''?>" <?=$active===$key?'aria-current="page"':''?> data-research-knowledge-view="<?=h($key)?>">
          <strong><?=h((string)$view['label'])?></strong>
          <span><?=h((string)$view['description'])?></span>
        </a>
      <?php endforeach?>
    </nav>
    <?php return (string)ob_get_clean();
}
