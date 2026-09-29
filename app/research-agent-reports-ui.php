<?php
declare(strict_types=1);

function research_agent_reports_views(): array {
    return [
        'create'=>[
            'label'=>'Create',
            'description'=>'Report Studio, governed report types, reusable presets, Research Documents, and briefing entry points.',
        ],
        'recent'=>[
            'label'=>'Recent',
            'description'=>'Report Runs, delivered intelligence, freshness, comparisons, provenance, and Research Documents.',
        ],
        'scheduled'=>[
            'label'=>'Scheduled',
            'description'=>'Report subscriptions driven by the existing Research Program scheduler and delivery runtime.',
        ],
        'published'=>[
            'label'=>'Published',
            'description'=>'Collaborative review, approval gates, immutable publication versions, and distribution.',
        ],
    ];
}

function research_agent_reports_view(string $candidate): string {
    $candidate=strtolower(trim($candidate));
    if(in_array($candidate,['run','studio','presets','preset'],true))$candidate='create';
    if(in_array($candidate,['inbox','deliveries','delivery','history'],true))$candidate='recent';
    if(in_array($candidate,['subscriptions','subscription','schedule'],true))$candidate='scheduled';
    if(in_array($candidate,['publishing','publication','publications'],true))$candidate='published';
    return array_key_exists($candidate,research_agent_reports_views())?$candidate:'create';
}

function research_agent_reports_href(string $agentPublic,string $view='create',array $query=[]): string {
    $view=research_agent_reports_view($view);
    $params=array_merge(['agent'=>trim($agentPublic),'view'=>$view],$query);
    $params=array_filter($params,static fn($value)=>$value!==''&&$value!==null);
    return '/research-reports.php?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
}

function research_agent_reports_engine_links(array $agent): array {
    $agentPublic=trim((string)($agent['public_id']??''));
    $agentQ=rawurlencode($agentPublic);
    return [
        'create'=>[
            ['label'=>'Report Studio','href'=>$agentPublic!==''?research_agent_reports_href($agentPublic,'create'):'/research-reports.php'],
            ['label'=>'Portfolio & Executive Briefings','href'=>'/research-intelligence-portfolios.php'],
        ],
        'recent'=>[
            ['label'=>'Report Runs','href'=>$agentPublic!==''?research_agent_reports_href($agentPublic,'recent'):'/research-reports.php'],
        ],
        'scheduled'=>[
            ['label'=>'Report Subscriptions','href'=>$agentPublic!==''?research_agent_reports_href($agentPublic,'scheduled'):'/research-reports.php'],
            ['label'=>'Research Programs','href'=>$agentPublic!==''?'/research-programs.php?agent='.$agentQ:'/research-programs.php'],
        ],
        'published'=>[
            ['label'=>'Governed Publishing','href'=>'/research-publications.php'.($agentPublic!==''?'?agent='.$agentQ:'')],
            ['label'=>'Review Center','href'=>'/research-reviews.php'],
        ],
    ];
}

function research_agent_reports_publication_href(string $agentPublic,array $workflow): string {
    $public=trim((string)($workflow['public_id']??''));
    if($public==='')return research_agent_reports_href($agentPublic,'published');
    return '/research-publications.php?workflow='.rawurlencode($public);
}

function research_agent_reports_render_nav(string $agentPublic,string $active='create',array $counts=[]): string {
    $active=research_agent_reports_view($active);
    ob_start();?>
    <nav class="researchUnifiedNav researchReportsUnifiedNav" aria-label="Report views">
      <?php foreach(research_agent_reports_views() as $key=>$view):?>
        <a href="<?=h(research_agent_reports_href($agentPublic,$key))?>" class="<?=$active===$key?'active':''?>" <?=$active===$key?'aria-current="page"':''?> data-reports-view="<?=h($key)?>">
          <strong><?=h((string)$view['label'])?><?php if(isset($counts[$key])):?><em><?=h((string)$counts[$key])?></em><?php endif?></strong>
          <span><?=h((string)$view['description'])?></span>
        </a>
      <?php endforeach?>
    </nav>
    <?php return (string)ob_get_clean();
}
