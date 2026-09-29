<?php
declare(strict_types=1);

/**
 * Phase 74 Section 6 — Portfolios & Global Attention presentation helpers.
 *
 * Phase 60/73 Intelligence Portfolios remain authoritative. This file only
 * normalizes canonical Portfolio views and the existing organization command
 * center read model into contextual attention cards.
 */

function research_portfolios_views(): array {
    return [
        'overview'=>['label'=>'Overview'],
        'portfolios'=>['label'=>'Portfolios'],
    ];
}

function research_portfolios_view(?string $value): string {
    $value=strtolower(trim((string)$value));
    $aliases=[
        ''=>'overview',
        'overview'=>'overview',
        'command-center'=>'overview',
        'command_center'=>'overview',
        'attention'=>'overview',
        'review'=>'overview',
        'reviews'=>'overview',
        'portfolio'=>'portfolios',
        'portfolios'=>'portfolios',
        'manage'=>'portfolios',
        'detail'=>'portfolios',
    ];
    return $aliases[$value]??'overview';
}

function research_portfolios_href(string $view='overview',?string $portfolioId=null,array $query=[]): string {
    $view=research_portfolios_view($view);
    $params=['view'=>$view];
    $portfolioId=trim((string)$portfolioId);
    if($portfolioId!=='')$params['portfolio']=$portfolioId;
    foreach($query as $key=>$value){
        if($value===null||$value==='')continue;
        $params[(string)$key]=(string)$value;
    }
    return '/research-intelligence-portfolios.php?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
}

function research_portfolios_attention_reason(array $reasons): string {
    $labels=[];
    foreach($reasons as $reason){
        $reason=trim((string)$reason);
        if($reason!=='')$labels[]=str_replace('_',' ',$reason);
    }
    return implode(' · ',$labels);
}

function research_portfolios_attention_item(string $label,string $meta,string $href,string $portfolioId=''): array {
    return [
        'label'=>trim($label),
        'meta'=>trim($meta),
        'href'=>$href,
        'portfolio_id'=>trim($portfolioId),
    ];
}

function research_portfolios_attention_sections(array $center,?string $portfolioId=null): array {
    $portfolioId=trim((string)$portfolioId);
    $portfolioHref=fn(string $id): string=>research_portfolios_href('portfolios',$id);
    $sections=[];

    $rows=[];
    foreach((array)($center['organizational_cognition_signals']??[]) as $x){
        $id=(string)($x['portfolio_id']??'');
        $rows[]=research_portfolios_attention_item(
            strtoupper((string)($x['severity']??'info')).' · '.(string)($x['title']??'Organizational signal'),
            trim((string)($x['portfolio_title']??'').' · '.str_replace('_',' ',(string)($x['kind']??'signal')).' · '.(string)($x['summary']??''),' ·'),
            $portfolioHref($id),
            $id
        );
    }
    $sections[]=['key'=>'cognition','eyebrow'=>'ORGANIZATIONAL COGNITION','title'=>'Governed strategic reasoning','items'=>$rows];

    $rows=[];
    foreach((array)($center['organizational_cognition_analogues']??[]) as $x){
        $id=(string)($x['portfolio_id']??'');
        $rows[]=research_portfolios_attention_item(
            (string)($x['label']??'Decision analogue'),
            trim((string)($x['portfolio_title']??'').' · '.(string)($x['decision_count']??0).' Decisions · '.(string)($x['evidence_count']??0).' evidence member(s) · '.(string)($x['summary']??''),' ·'),
            $portfolioHref($id),
            $id
        );
    }
    $sections[]=['key'=>'analogues','eyebrow'=>'DECISION ANALOGUES','title'=>'Exact organizational memory','items'=>$rows];

    $rows=[];
    foreach((array)($center['strategic_briefing_attention']??[]) as $x){
        $id=(string)($x['portfolio_id']??'');$review=(string)($x['team_review_id']??'');
        $meta=trim((string)($x['portfolio_title']??'').' · '.str_replace('_',' ',(string)($x['consensus']??'awaiting_reviewers')),' ·');
        if(!empty($x['due_at']))$meta.=' · due '.(string)$x['due_at'];
        $reason=research_portfolios_attention_reason((array)($x['reasons']??[]));if($reason!=='')$meta.=' · '.$reason;
        $rows[]=research_portfolios_attention_item((string)($x['title']??'Executive Strategic Briefing'),$meta,$review!==''?'/research-reviews.php?id='.rawurlencode($review):$portfolioHref($id),$id);
    }
    $sections[]=['key'=>'strategic_briefings','eyebrow'=>'EXECUTIVE STRATEGIC BRIEFINGS','title'=>'Team review attention','items'=>$rows];

    $rows=[];
    foreach((array)($center['strategic_review_attention']??[]) as $x){
        $id=(string)($x['portfolio_id']??'');$review=(string)($x['review_id']??'');
        $meta=str_replace('_',' ',(string)($x['consensus']??'awaiting_reviewers'));
        if(!empty($x['due_at']))$meta.=' · due '.(string)$x['due_at'];
        $reason=research_portfolios_attention_reason((array)($x['reasons']??[]));if($reason!=='')$meta.=' · '.$reason;
        $rows[]=research_portfolios_attention_item((string)($x['portfolio_title']??'Strategic Review'),$meta,$review!==''?'/research-reviews.php?id='.rawurlencode($review):$portfolioHref($id),$id);
    }
    $sections[]=['key'=>'strategic_reviews','eyebrow'=>'STRATEGIC REVIEW','title'=>'Human review attention','items'=>$rows];

    $rows=[];
    foreach((array)($center['strategic_graph_attention']??[]) as $x){
        $id=(string)($x['portfolio_id']??'');
        $label=(string)($x['source_title']??'Source').' → '.(string)($x['target_title']??'Target');
        $meta=trim((string)($x['portfolio_title']??'').' · '.strtoupper(str_replace('_',' ',(string)($x['relation_type']??'relationship'))).' · '.strtoupper((string)($x['materiality']??'medium')),' ·');
        if(!empty($x['cross_portfolio']))$meta.=' · cross-portfolio';
        if(!empty($x['stale']))$meta.=' · stale';
        $reason=research_portfolios_attention_reason((array)($x['reasons']??[]));if($reason!=='')$meta.=' · '.$reason;
        $rows[]=research_portfolios_attention_item($label,$meta,$portfolioHref($id),$id);
    }
    $sections[]=['key'=>'strategic_graph','eyebrow'=>'STRATEGIC DEPENDENCIES','title'=>'Relationship attention','items'=>$rows];

    $rows=[];
    foreach((array)($center['learning_patterns']??[]) as $x){
        $id=(string)($x['portfolio_id']??'');
        $rows[]=research_portfolios_attention_item(
            (string)($x['label']??'Pattern'),
            trim((string)($x['portfolio_title']??'').' · '.strtoupper(str_replace('_',' ',(string)($x['pattern_type']??'pattern'))).' · '.(string)($x['decision_count']??0).' Decisions · '.(string)($x['evidence_count']??0).' evidence member(s)',' ·'),
            $portfolioHref($id),
            $id
        );
    }
    $sections[]=['key'=>'learning','eyebrow'=>'CROSS-DECISION LEARNING','title'=>'Pattern Memory','items'=>$rows];

    $rows=[];
    foreach((array)($center['decision_execution_attention']??[]) as $x){
        $id=(string)($x['portfolio_id']??'');$plan=(string)($x['action_plan_public_id']??'');
        $meta=trim((string)($x['portfolio_title']??'').' · '.(string)($x['decision_title']??'').' · '.strtoupper((string)($x['action_plan_status']??'')),' ·');
        $reason=research_portfolios_attention_reason((array)($x['reasons']??[]));if($reason!=='')$meta.=' · '.$reason;
        $rows[]=research_portfolios_attention_item((string)($x['action_plan_title']??'Action Plan'),$meta,$plan!==''?'/research-action-plans.php?action_plan='.rawurlencode($plan):$portfolioHref($id),$id);
    }
    $sections[]=['key'=>'execution','eyebrow'=>'DECISION & EXECUTION','title'=>'Strategic follow-through','items'=>$rows];

    $rows=[];
    foreach((array)($center['needs_attention']??[]) as $x){
        $id=(string)($x['portfolio_id']??'');
        $meta=(string)($x['risks']??0).' risks · '.(string)($x['tensions']??0).' tensions';
        if(!empty($x['briefing_due']))$meta.=' · briefing due';
        $rows[]=research_portfolios_attention_item((string)($x['title']??'Portfolio'),$meta,$portfolioHref($id),$id);
    }
    $sections[]=['key'=>'portfolio_attention','eyebrow'=>'NEEDS ATTENTION','title'=>'Portfolio attention','items'=>$rows];

    $rows=[];
    foreach((array)($center['new_since_last_briefing']??[]) as $x){
        $id=(string)($x['portfolio_id']??'');$cycle=(array)($x['cycle']??[]);
        $rows[]=research_portfolios_attention_item((string)($x['title']??'Portfolio'),(string)($cycle['detail']??'Material Portfolio change recorded.'),$portfolioHref($id),$id);
    }
    $sections[]=['key'=>'movement','eyebrow'=>'NEW SINCE LAST BRIEFING','title'=>'Material movement','items'=>$rows];

    $rows=[];
    foreach((array)($center['decisions_awaiting_follow_through']??[]) as $x){
        $id=(string)($x['portfolio_id']??'');$task=(string)($x['task_public_id']??'');
        $meta=trim((string)($x['portfolio_title']??'').' · '.(string)($x['task_status']??''),' ·');
        if(!empty($x['due_at']))$meta.=' · due '.(string)$x['due_at'];
        $rows[]=research_portfolios_attention_item((string)($x['title']??'Decision follow-through'),$meta,$task!==''?'/research-tasks.php?task='.rawurlencode($task):$portfolioHref($id),$id);
    }
    $sections[]=['key'=>'legacy_follow_through','eyebrow'=>'HISTORICAL FOLLOW-THROUGH','title'=>'Historical task commitments','items'=>$rows];

    $rows=[];
    foreach((array)($center['emerging_opportunities']??[]) as $x){
        $id=(string)($x['portfolio_id']??'');
        $rows[]=research_portfolios_attention_item((string)($x['portfolio_title']??'Portfolio'),(string)($x['summary']??''),$portfolioHref($id),$id);
    }
    $sections[]=['key'=>'opportunities','eyebrow'=>'EMERGING OPPORTUNITIES','title'=>'Positive movement','items'=>$rows];

    $rows=[];
    foreach((array)($center['cross_portfolio_themes']??[]) as $x){
        $names=array_values((array)($x['portfolios']??[]));
        $rows[]=research_portfolios_attention_item((string)($x['key']??'Shared signal'),(string)($x['portfolio_count']??0).' Portfolios'.($names?' · '.implode(' · ',$names):''),research_portfolios_href('portfolios'),'');
    }
    $sections[]=['key'=>'themes','eyebrow'=>'CROSS-PORTFOLIO THEMES','title'=>'Shared organizational signals','items'=>$rows];

    $rows=[];
    foreach((array)($center['briefings_awaiting_review']??[]) as $x){
        $id=(string)($x['portfolio_id']??'');$workflow=(string)($x['publication_public_id']??'');
        $meta=trim((string)($x['portfolio_title']??'').' · '.(string)($x['publication_status']??$x['status']??''),' ·');
        $rows[]=research_portfolios_attention_item((string)($x['title']??'Executive Briefing'),$meta,$workflow!==''?'/research-publications.php?workflow='.rawurlencode($workflow):$portfolioHref($id),$id);
    }
    $sections[]=['key'=>'briefing_review','eyebrow'=>'BRIEFINGS AWAITING REVIEW','title'=>'Human governance','items'=>$rows];

    foreach($sections as &$section){
        if($portfolioId!==''){
            $section['items']=array_values(array_filter((array)$section['items'],fn($item)=>(string)($item['portfolio_id']??'')===$portfolioId));
        }
        $section['count']=count((array)$section['items']);
    }
    unset($section);
    return $sections;
}

function research_portfolios_attention_count(array $sections): int {
    $count=0;foreach($sections as $section)$count+=(int)($section['count']??count((array)($section['items']??[])));
    return $count;
}
