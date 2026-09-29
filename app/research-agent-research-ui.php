<?php
declare(strict_types=1);

function research_agent_research_views(): array {
    return [
        'missions'=>[
            'label'=>'Missions',
            'description'=>'Outcome-driven research objectives, success criteria, questions, and progress.',
        ],
        'tasks'=>[
            'label'=>'Tasks',
            'description'=>'Plans, execution work, completion gates, review queues, and deliverables.',
        ],
        'decisions'=>[
            'label'=>'Decisions',
            'description'=>'Decision Ledger, rationale, reconsideration signals, reviews, and Outcome Memory.',
        ],
        'follow_through'=>[
            'label'=>'Follow-through',
            'description'=>'Action Plans, execution governance, variance, milestones, and outcome handoff.',
        ],
        'recurring'=>[
            'label'=>'Recurring',
            'description'=>'Programs and compatible automations that keep research current over time.',
        ],
    ];
}

function research_agent_research_view(string $candidate): string {
    $candidate=strtolower(trim($candidate));
    if($candidate==='follow-through'||$candidate==='followthrough'||$candidate==='action-plans')$candidate='follow_through';
    if($candidate==='programs'||$candidate==='automations')$candidate='recurring';
    return array_key_exists($candidate,research_agent_research_views())?$candidate:'missions';
}

function research_agent_research_href(string $agentPublic,string $view='missions',array $query=[]): string {
    $view=research_agent_research_view($view);
    $params=array_merge(['agent'=>trim($agentPublic),'view'=>$view],$query);
    $params=array_filter($params,static fn($value)=>$value!==''&&$value!==null);
    return '/research-agent-research.php?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
}

function research_agent_research_engine_links(array $agent): array {
    $agentPublic=trim((string)($agent['public_id']??''));
    $agentQ=rawurlencode($agentPublic);
    return [
        'missions'=>[
            ['label'=>'Manage Missions','href'=>$agentPublic!==''?'/research-missions.php?agent='.$agentQ:''],
        ],
        'tasks'=>[
            ['label'=>'Manage Tasks & Plans','href'=>$agentPublic!==''?'/research-tasks.php?agent='.$agentQ:''],
        ],
        'decisions'=>[
            ['label'=>'Open Decision Command Center','href'=>'/research-decisions.php'],
        ],
        'follow_through'=>[
            ['label'=>'Open Action Plan Command Center','href'=>'/research-action-plans.php'],
        ],
        'recurring'=>[
            ['label'=>'Manage Recurring Research','href'=>$agentPublic!==''?'/research-programs.php?agent='.$agentQ:''],
        ],
    ];
}

function research_agent_research_item_href(string $view,string $agentPublic,array $item): string {
    $view=research_agent_research_view($view);$agentQ=rawurlencode(trim($agentPublic));$public=rawurlencode(trim((string)($item['public_id']??'')));
    if($public==='')return research_agent_research_href($agentPublic,$view);
    return match($view){
        'missions'=>'/research-missions.php?agent='.$agentQ.'&mission='.$public,
        'tasks'=>'/research-tasks.php?agent='.$agentQ.'&plan='.$public,
        'decisions'=>'/research-decisions.php?decision='.$public,
        'follow_through'=>'/research-action-plans.php?action_plan='.$public,
        'recurring'=>'/research-programs.php?agent='.$agentQ.'&program='.$public,
        default=>research_agent_research_href($agentPublic,$view),
    };
}

function research_agent_research_render_nav(string $agentPublic,string $active='missions'): string {
    $active=research_agent_research_view($active);
    ob_start();?>
    <nav class="researchUnifiedNav" aria-label="Research views">
      <?php foreach(research_agent_research_views() as $key=>$view):?>
        <a href="<?=h(research_agent_research_href($agentPublic,$key))?>" class="<?=$active===$key?'active':''?>" <?=$active===$key?'aria-current="page"':''?> data-research-view="<?=h($key)?>">
          <strong><?=h((string)$view['label'])?></strong>
          <span><?=h((string)$view['description'])?></span>
        </a>
      <?php endforeach?>
    </nav>
    <?php return (string)ob_get_clean();
}
