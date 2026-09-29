<?php
declare(strict_types=1);

/**
 * Phase 74 Section 1 — Canonical Research UI & Compatibility Map.
 *
 * This file is an architecture contract, not a router. Section 1 changes no
 * user route behavior and deletes no storage. Later Phase 74 sections may use
 * this map to consolidate UI while preserving the mature engines underneath.
 */

function research_surface_classifications(): array {
    return ['KEEP_ENGINE','MERGE_UI','HIDE','LEGACY_ROUTE'];
}

function research_canonical_ui_model(): array {
    return [
        'global'=>[
            'research.home'=>['label'=>'Research','children'=>['research.agents','portfolios']],
            'research.agents'=>['label'=>'Research Agents'],
            'portfolios'=>['label'=>'Portfolios','children'=>['portfolios.overview','portfolios.detail']],
            'portfolios.overview'=>['label'=>'Overview'],
            'portfolios.detail'=>['label'=>'Portfolio'],
            'attention'=>['label'=>'Needs Attention','nav'=>false],
        ],
        'agent'=>[
            'agent.chat'=>['label'=>'Chat'],
            'agent.knowledge'=>['label'=>'Knowledge','children'=>['agent.knowledge.library','agent.knowledge.insights','agent.knowledge.changes']],
            'agent.knowledge.library'=>['label'=>'Library'],
            'agent.knowledge.insights'=>['label'=>'Insights'],
            'agent.knowledge.changes'=>['label'=>'Changes'],
            'agent.research'=>['label'=>'Research','children'=>['agent.research.missions','agent.research.tasks','agent.research.decisions','agent.research.follow_through','agent.research.recurring']],
            'agent.research.missions'=>['label'=>'Missions'],
            'agent.research.tasks'=>['label'=>'Tasks'],
            'agent.research.decisions'=>['label'=>'Decisions'],
            'agent.research.follow_through'=>['label'=>'Follow-through'],
            'agent.research.recurring'=>['label'=>'Recurring'],
            'agent.reports'=>['label'=>'Reports','children'=>['agent.reports.create','agent.reports.recent','agent.reports.scheduled','agent.reports.published']],
            'agent.reports.create'=>['label'=>'Create'],
            'agent.reports.recent'=>['label'=>'Recent'],
            'agent.reports.scheduled'=>['label'=>'Scheduled'],
            'agent.reports.published'=>['label'=>'Published'],
        ],
    ];
}

function research_concept_aliases(): array {
    return [
        'research_project'=>['surface'=>'Research Agent','target'=>'agent.chat','rule'=>'Keep research_projects as the permission/data boundary; stop presenting Project as a competing primary product concept.'],
        'research_program'=>['surface'=>'Recurring Research','target'=>'agent.research.recurring','rule'=>'Keep Program runtime and history; package recurrence as a Research setting.'],
        'research_automation'=>['surface'=>'Recurring Research','target'=>'agent.research.recurring','rule'=>'Keep Phase 18 runtime/API for compatibility; retire Automations as a standalone primary destination.'],
        'research_monitoring'=>['surface'=>'Knowledge → Changes','target'=>'agent.knowledge.changes','rule'=>'Keep watches and monitoring workers; expose them where users manage changing knowledge.'],
        'research_action_plan'=>['surface'=>'Follow-through','target'=>'agent.research.follow_through','rule'=>'Keep Phase 72 Action Plans authoritative; simplify the user-facing label and placement.'],
        'research_outcome_decision_memory'=>['surface'=>'Decisions → Outcome Memory','target'=>'agent.research.decisions','rule'=>'Keep historical outcome-learning data; Phase 71 Decision Ledger is the only primary Decision concept.'],
        'research_review'=>['surface'=>'Contextual Review / Needs Attention','target'=>'attention','rule'=>'Keep Collaborative Review authoritative; show review state on the object being reviewed plus a global attention inbox.'],
        'research_publication'=>['surface'=>'Reports → Published','target'=>'agent.reports.published','rule'=>'Keep Phase 59 publishing authoritative; publishing is a Report state/action, not a separate product.'],
        'research_brief'=>['surface'=>'Report template','target'=>'agent.reports','rule'=>'Keep historical brief records and generation logic; Research Brief is a Report type.'],
        'executive_strategic_briefing'=>['surface'=>'Report template','target'=>'agent.reports','rule'=>'Keep frozen packet, Team Review, Portfolio lineage, and publication gates; expose as governed Report types.'],
        'research_portfolio_legacy'=>['surface'=>'Research overview','target'=>'research.home','rule'=>'Keep legacy Phase 23 portfolio data/attention logic for compatibility; Intelligence Portfolio is the only primary Portfolio concept.'],
        'intelligence_portfolio'=>['surface'=>'Portfolios','target'=>'portfolios','rule'=>'Keep Phase 60/73 Intelligence Portfolio as the canonical Portfolio engine.'],
        'report_studio'=>['surface'=>'Reports','target'=>'agent.reports','rule'=>'Keep System Reports, Report Runs, Report Studio, presets, delivery, provenance, and documents as one Reports engine.'],
        'desktop_workspace'=>['surface'=>'Knowledge / workspace mode','target'=>'agent.knowledge.library','rule'=>'Keep Desktop and workspace object logic; Desktop is a view of Agent files, not a separate research system.'],
        'vp3_library'=>['surface'=>'Knowledge → Library','target'=>'agent.knowledge.library','rule'=>'Keep VP3 connection/import and vp3-library.php compatibility; package imported VP3 research into the unified Knowledge Library.'],
    ];
}

function research_route_surface_map(): array {
    return [
        'cross-research.php'=>['classification'=>'MERGE_UI','target'=>'agent.knowledge.insights','reason'=>'Cross-project intelligence becomes an Insights view/filter, not a standalone destination.'],
        'evidence.php'=>['classification'=>'HIDE','target'=>'agent.knowledge.insights','reason'=>'Evidence detail remains an inspector/deep link.'],
        'report-status.php'=>['classification'=>'LEGACY_ROUTE','target'=>'agent.reports.recent','reason'=>'Legacy report status folds into Reports history.'],
        'report.php'=>['classification'=>'LEGACY_ROUTE','target'=>'agent.reports.recent','reason'=>'Legacy report reader folds into Reports.'],
        'research-action-plans.php'=>['classification'=>'MERGE_UI','target'=>'agent.research.follow_through','reason'=>'Keep Phase 72 logic; present Action Plans as Decision follow-through.'],
        'research-agent-knowledge.php'=>['classification'=>'MERGE_UI','target'=>'agent.knowledge','reason'=>'Primary foundation for the unified Agent Knowledge tab.'],
        'research-agent-research.php'=>['classification'=>'MERGE_UI','target'=>'agent.research','reason'=>'Canonical unified Agent Research tab packages Missions, Tasks, Decisions, Follow-through, and Recurring work.'],
        'research-audit-export.php'=>['classification'=>'HIDE','target'=>'agent.knowledge.insights','reason'=>'Export action remains available from provenance/audit inspectors.'],
        'research-audit-receipt.php'=>['classification'=>'HIDE','target'=>'agent.knowledge.insights','reason'=>'Audit receipt remains a deep-link inspector.'],
        'research-audit.php'=>['classification'=>'HIDE','target'=>'agent.knowledge.insights','reason'=>'Audit remains object-level advanced detail.'],
        'research-automations.php'=>['classification'=>'LEGACY_ROUTE','target'=>'agent.research.recurring','reason'=>'Phase 18 remains compatible; recurring work moves under Research.'],
        'research-brief.php'=>['classification'=>'LEGACY_ROUTE','target'=>'agent.reports','reason'=>'Research Brief becomes a report template/type.'],
        'research-citations.php'=>['classification'=>'HIDE','target'=>'agent.knowledge.insights','reason'=>'Citations are an object inspector, not primary navigation.'],
        'research-claim.php'=>['classification'=>'HIDE','target'=>'agent.knowledge.insights','reason'=>'Claim detail stays deep-linkable inside Insights.'],
        'research-decisions.php'=>['classification'=>'MERGE_UI','target'=>'agent.research.decisions','reason'=>'Phase 71 remains the canonical Decision Ledger.'],
        'research-entities.php'=>['classification'=>'MERGE_UI','target'=>'agent.knowledge.insights','reason'=>'Entity index becomes an Insights view.'],
        'research-entity.php'=>['classification'=>'HIDE','target'=>'agent.knowledge.insights','reason'=>'Entity detail remains an inspector/deep link.'],
        'research-evidence-pack-export.php'=>['classification'=>'HIDE','target'=>'agent.knowledge.insights','reason'=>'Evidence Pack export remains an action.'],
        'research-evidence-pack.php'=>['classification'=>'HIDE','target'=>'agent.knowledge.insights','reason'=>'Evidence Pack detail remains an advanced inspector.'],
        'research-evidence-packs.php'=>['classification'=>'MERGE_UI','target'=>'agent.knowledge.insights','reason'=>'Evidence Packs become an Insights collection/filter.'],
        'research-evolution.php'=>['classification'=>'MERGE_UI','target'=>'agent.knowledge.changes','reason'=>'Longitudinal/evolution views become Knowledge Changes.'],
        'research-finding.php'=>['classification'=>'HIDE','target'=>'agent.knowledge.insights','reason'=>'Finding detail remains an inspector/deep link.'],
        'research-graph.php'=>['classification'=>'HIDE','target'=>'agent.knowledge.insights','reason'=>'Knowledge graph becomes an Insights visualization.'],
        'research-impact.php'=>['classification'=>'HIDE','target'=>'agent.knowledge.changes','reason'=>'Impact analysis becomes a Changes inspector.'],
        'research-intelligence-command-center.php'=>['classification'=>'LEGACY_ROUTE','target'=>'portfolios.overview','reason'=>'Organization Command Center folds into Portfolios Overview.'],
        'research-intelligence-portfolios.php'=>['classification'=>'MERGE_UI','target'=>'portfolios','reason'=>'Phase 60/73 Intelligence Portfolio is the canonical Portfolio surface.'],
        'research-knowledge.php'=>['classification'=>'LEGACY_ROUTE','target'=>'agent.knowledge','reason'=>'Project-centric Knowledge folds into Agent Knowledge.'],
        'research-missions.php'=>['classification'=>'MERGE_UI','target'=>'agent.research.missions','reason'=>'Missions remain the user-facing research objective.'],
        'research-monitoring.php'=>['classification'=>'MERGE_UI','target'=>'agent.knowledge.changes','reason'=>'Monitoring is how Knowledge Changes stays current.'],
        'research-network.php'=>['classification'=>'HIDE','target'=>'agent.knowledge.insights','reason'=>'Network view becomes an Insights visualization.'],
        'research-outcomes.php'=>['classification'=>'LEGACY_ROUTE','target'=>'agent.research.decisions','reason'=>'Old Decision Memory folds into Phase 71 Decisions and Outcome Memory.'],
        'research-portfolio.php'=>['classification'=>'LEGACY_ROUTE','target'=>'research.home','reason'=>'Legacy Research Portfolio attention view folds into Research home; Intelligence Portfolios remain canonical.'],
        'research-programs.php'=>['classification'=>'MERGE_UI','target'=>'agent.research.recurring','reason'=>'Programs remain the recurring-research engine.'],
        'research-project.php'=>['classification'=>'LEGACY_ROUTE','target'=>'agent.chat','reason'=>'Project stays internal as the permission/data boundary; Agent becomes the primary workspace concept.'],
        'research-provenance.php'=>['classification'=>'HIDE','target'=>'agent.knowledge.insights','reason'=>'Provenance remains an inspector on knowledge/report objects.'],
        'research-publications.php'=>['classification'=>'MERGE_UI','target'=>'agent.reports.published','reason'=>'Publications become the Published Reports view.'],
        'research-publish.php'=>['classification'=>'HIDE','target'=>'agent.reports.published','reason'=>'Publication action remains governed but contextual.'],
        'research-report-diff.php'=>['classification'=>'HIDE','target'=>'agent.reports.recent','reason'=>'Report diff remains a report inspector.'],
        'research-report-export.php'=>['classification'=>'HIDE','target'=>'agent.reports.recent','reason'=>'Report export remains an action.'],
        'research-report-network.php'=>['classification'=>'HIDE','target'=>'agent.reports.recent','reason'=>'Report evidence network remains an inspector.'],
        'research-report-provenance.php'=>['classification'=>'HIDE','target'=>'agent.reports.recent','reason'=>'Report provenance remains an inspector.'],
        'research-report.php'=>['classification'=>'MERGE_UI','target'=>'agent.reports.recent','reason'=>'System Report detail becomes Reports history/detail.'],
        'research-reports.php'=>['classification'=>'MERGE_UI','target'=>'agent.reports','reason'=>'Primary foundation for unified Reports.'],
        'research-reviews.php'=>['classification'=>'LEGACY_ROUTE','target'=>'attention','reason'=>'Reviews become contextual on their subject plus global Needs Attention.'],
        'research-tasks.php'=>['classification'=>'MERGE_UI','target'=>'agent.research.tasks','reason'=>'Tasks remain the canonical unit of research work.'],
        'research-timeline.php'=>['classification'=>'HIDE','target'=>'agent.knowledge.insights','reason'=>'Timeline becomes an Insights visualization.'],
        'research-topic.php'=>['classification'=>'HIDE','target'=>'agent.knowledge.insights','reason'=>'Topic detail remains an inspector.'],
        'research-verification.php'=>['classification'=>'HIDE','target'=>'agent.knowledge.insights','reason'=>'Verification remains an inspector/action on knowledge.'],
        'research-workspace-file.php'=>['classification'=>'HIDE','target'=>'agent.knowledge.library','reason'=>'Workspace file detail stays deep-linkable from Library/Desktop.'],
        'research.php'=>['classification'=>'MERGE_UI','target'=>'research.home','reason'=>'Canonical global Research entry simplifies to Agents + Portfolios.','role'=>'canonical_entry'],
        'source-compare.php'=>['classification'=>'HIDE','target'=>'agent.knowledge.changes','reason'=>'Source comparison remains a Changes inspector.'],
        'source.php'=>['classification'=>'HIDE','target'=>'agent.knowledge.library','reason'=>'Source detail remains a Library inspector/deep link.'],
    ];
}

function research_engine_surface_map(): array {
    $domains=[
        'app/cognitive-feed-ui.php'=>'attention','app/cognitive-feed.php'=>'attention','app/cross-research.php'=>'knowledge.insights',
        'app/living-research.php'=>'knowledge.changes','app/proactive-intelligence.php'=>'knowledge.changes',
        'app/research-action-plan-cognition.php'=>'research.follow_through','app/research-action-plan-outcomes.php'=>'research.follow_through',
        'app/research-action-plan-variance.php'=>'research.follow_through','app/research-action-plans.php'=>'research.follow_through',
        'app/research-agent-shell-ui.php'=>'agent','app/research-agent-knowledge-ui.php'=>'knowledge','app/research-agent-research-ui.php'=>'research','app/research-agent-reports-ui.php'=>'reports','app/research-agent-workspace-ui.php'=>'workspace','app/research-agent-workspace.php'=>'workspace','app/research-agents.php'=>'agent',
        'app/research-automation.php'=>'research.recurring','app/research-autonomy.php'=>'research','app/research-decisions.php'=>'research.decisions',
        'app/research-entities.php'=>'knowledge.insights','app/research-evidence-packs.php'=>'knowledge.insights',
        'app/research-intelligence-decision-rollups.php'=>'portfolios','app/research-intelligence-delivery.php'=>'reports',
        'app/research-intelligence-operations.php'=>'portfolios','app/research-intelligence-organizational-cognition.php'=>'portfolios',
        'app/research-intelligence-pattern-memory.php'=>'portfolios','app/research-intelligence-portfolios.php'=>'portfolios',
        'app/research-intelligence-strategic-briefings.php'=>'reports','app/research-intelligence-strategic-graph.php'=>'portfolios',
        'app/research-intelligence-strategic-reviews.php'=>'portfolios','app/research-intelligence.php'=>'knowledge.insights',
        'app/research-knowledge.php'=>'knowledge','app/research-library.php'=>'knowledge.library',
        'app/research-longitudinal-intelligence.php'=>'knowledge.changes','app/research-missions.php'=>'research.missions',
        'app/research-monitoring.php'=>'knowledge.changes','app/research-network.php'=>'knowledge.insights',
        'app/research-outcomes.php'=>'research.decisions','app/research-portfolio.php'=>'compatibility',
        'app/research-programs.php'=>'research.recurring','app/research-provenance.php'=>'knowledge.insights',
        'app/research-publishing.php'=>'reports.published','app/research-report-studio.php'=>'reports',
        'app/research-reports.php'=>'reports','app/research-retrieval.php'=>'knowledge',
        'app/research-reviews.php'=>'governance','app/research-surface-map.php'=>'architecture','app/research-system-reports.php'=>'reports',
        'app/research-tasks.php'=>'research.tasks','app/research-verification.php'=>'knowledge.insights',
        'app/research-workflow.php'=>'workspace','app/research-workspace.php'=>'workspace',
    ];
    $out=[];foreach($domains as $path=>$domain)$out[$path]=['classification'=>'KEEP_ENGINE','domain'=>$domain];
    return $out;
}

function research_api_surface_map(): array {
    $domains=[
        'api/cognitive-feed.php'=>'attention','api/cross-research.php'=>'knowledge.insights','api/living-research.php'=>'knowledge.changes',
        'api/proactive-intelligence.php'=>'knowledge.changes','api/research-action-plans.php'=>'research.follow_through',
        'api/research-agents.php'=>'agent','api/research-automations.php'=>'research.recurring','api/research-autonomy.php'=>'research',
        'api/research-decisions.php'=>'research.decisions','api/research-evidence-packs.php'=>'knowledge.insights',
        'api/research-intelligence-delivery.php'=>'reports','api/research-intelligence-portfolios.php'=>'portfolios',
        'api/research-longitudinal.php'=>'knowledge.changes','api/research-missions.php'=>'research.missions',
        'api/research-monitoring.php'=>'knowledge.changes','api/research-network.php'=>'knowledge.insights',
        'api/research-outcomes.php'=>'research.decisions','api/research-portfolio.php'=>'compatibility',
        'api/research-programs.php'=>'research.recurring','api/research-provenance.php'=>'knowledge.insights',
        'api/research-publications.php'=>'reports.published','api/research-retrieval.php'=>'knowledge',
        'api/research-reviews.php'=>'governance','api/research-system-reports.php'=>'reports','api/research-tasks.php'=>'research.tasks',
        'api/research-verification.php'=>'knowledge.insights','api/research-workspace-objects.php'=>'workspace',
        'api/research-workspace-upload.php'=>'workspace','api/research-workspace.php'=>'workspace',
    ];
    $compatibility=['api/research-automations.php'=>true,'api/research-outcomes.php'=>true,'api/research-portfolio.php'=>true];
    $out=[];foreach($domains as $path=>$domain)$out[$path]=['classification'=>'KEEP_ENGINE','domain'=>$domain,'compatibility_only'=>!empty($compatibility[$path])];
    return $out;
}

function research_database_concept_map(): array {
    return [
        'agent_project_boundary'=>['classification'=>'KEEP_ENGINE','surface'=>'Research Agent','modules'=>['app/research-agents.php','app/research-workspace.php'],'rule'=>'Preserve Agent↔Project linkage, ownership, Team permissions, and historical project IDs.'],
        'workspace_library'=>['classification'=>'KEEP_ENGINE','surface'=>'Knowledge → Library','modules'=>['app/research-agent-workspace.php','app/research-library.php'],'rule'=>'Preserve files, docs, recordings, bookmarks, notes, desktop objects, and workspace history.'],
        'knowledge_evidence'=>['classification'=>'KEEP_ENGINE','surface'=>'Knowledge → Insights','modules'=>['app/research-knowledge.php','app/research-intelligence.php','app/research-entities.php','app/research-evidence-packs.php'],'rule'=>'Preserve sources, annotations, Claims, Findings, Entities, relationships, evidence gaps, citations, provenance, verification, and evidence packs.'],
        'retrieval_context'=>['classification'=>'KEEP_ENGINE','surface'=>'Knowledge','modules'=>['app/research-retrieval.php'],'rule'=>'Phase 54 retrieval remains the canonical semantic retrieval/context layer.'],
        'monitoring_change_intelligence'=>['classification'=>'KEEP_ENGINE','surface'=>'Knowledge → Changes','modules'=>['app/research-monitoring.php','app/research-longitudinal-intelligence.php','app/proactive-intelligence.php','app/living-research.php'],'rule'=>'Preserve watches, freshness, source change history, impact, evolution, and longitudinal intelligence.'],
        'missions_tasks'=>['classification'=>'KEEP_ENGINE','surface'=>'Research → Missions / Tasks','modules'=>['app/research-missions.php','app/research-tasks.php'],'rule'=>'Mission remains the research objective; Task remains the unit of work.'],
        'recurring_research'=>['classification'=>'KEEP_ENGINE','surface'=>'Research → Recurring','modules'=>['app/research-programs.php','app/research-automation.php'],'rule'=>'Programs remain canonical for recurring research; Phase 18 Automations remain compatible during migration of the UI.'],
        'decisions_outcomes'=>['classification'=>'KEEP_ENGINE','surface'=>'Research → Decisions','modules'=>['app/research-decisions.php','app/research-outcomes.php'],'rule'=>'Phase 71 Decision Ledger remains authoritative; historical outcome-learning records are preserved as compatibility/history.'],
        'follow_through'=>['classification'=>'KEEP_ENGINE','surface'=>'Research → Follow-through','modules'=>['app/research-action-plans.php','app/research-action-plan-variance.php','app/research-action-plan-cognition.php','app/research-action-plan-outcomes.php'],'rule'=>'Phase 72 Action Plans, variance, review, Programs, and Outcome handoff remain authoritative.'],
        'collaborative_review'=>['classification'=>'KEEP_ENGINE','surface'=>'Contextual Review / Needs Attention','modules'=>['app/research-reviews.php'],'rule'=>'One Collaborative Review engine remains authoritative across reports, decisions, action plans, strategic reviews, and briefings.'],
        'reports_documents'=>['classification'=>'KEEP_ENGINE','surface'=>'Reports','modules'=>['app/research-system-reports.php','app/research-report-studio.php','app/research-reports.php'],'rule'=>'System Reports, Report Runs, Report Studio, templates/presets, Research Docs, and provenance become one Reports experience.'],
        'publishing_delivery'=>['classification'=>'KEEP_ENGINE','surface'=>'Reports → Scheduled / Published','modules'=>['app/research-publishing.php','app/research-intelligence-delivery.php'],'rule'=>'Phase 59 publication and existing delivery/subscription logic remain authoritative.'],
        'portfolio_organizational_intelligence'=>['classification'=>'KEEP_ENGINE','surface'=>'Portfolios','modules'=>['app/research-intelligence-portfolios.php','app/research-intelligence-decision-rollups.php','app/research-intelligence-pattern-memory.php','app/research-intelligence-strategic-graph.php','app/research-intelligence-strategic-reviews.php','app/research-intelligence-strategic-briefings.php','app/research-intelligence-organizational-cognition.php'],'rule'=>'Phase 60/73 Intelligence Portfolio is canonical; preserve rollups, Pattern Memory, Strategic Graph, frozen reviews/briefings, and cognition.'],
        'agent_action_governance'=>['classification'=>'KEEP_ENGINE','surface'=>'Chat + contextual confirmation','modules'=>['app/agent-actions.php','app/agent-chat.php'],'rule'=>'Preserve proposal/confirmation ledger, state hashes, idempotency, and authority boundaries.'],
        'legacy_compatibility'=>['classification'=>'KEEP_ENGINE','surface'=>'Compatibility only','modules'=>['app/research-portfolio.php','app/research-automation.php','app/research-outcomes.php'],'rule'=>'Keep historical stores and APIs until Phase 74 compatibility telemetry/tests prove UI migration is complete.'],
    ];
}

function research_surface_map(): array {
    return [
        'version'=>'phase74.section1',
        'principles'=>[
            'Keep mature logic; consolidate product presentation.',
            'One user concept gets one obvious place in the UI.',
            'Research Agent is the primary workspace concept; Project remains the internal permission/data boundary.',
            'Agent primary tabs are Chat, Knowledge, Research, and Reports.',
            'Knowledge primary views are Library, Insights, and Changes.',
            'Research primary views are Missions, Tasks, Decisions, Follow-through, and Recurring.',
            'Reports primary views are Create, Recent, Scheduled, and Published.',
            'Intelligence Portfolio is the only primary Portfolio concept.',
            'Advanced provenance, citations, verification, graphs, audits, and exports remain contextual inspectors/actions.',
            'Legacy routes and APIs stay compatible until later Phase 74 sections redirect/deep-link them.',
            'No destructive data migration is part of UI consolidation.',
        ],
        'canonical_ui'=>research_canonical_ui_model(),
        'aliases'=>research_concept_aliases(),
        'routes'=>research_route_surface_map(),
        'engines'=>research_engine_surface_map(),
        'apis'=>research_api_surface_map(),
        'database_concepts'=>research_database_concept_map(),
    ];
}
