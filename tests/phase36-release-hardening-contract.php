<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function p36c(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}

$cognitive=(string)file_get_contents($root.'/app/cognitive-feed.php');
$actions=(string)file_get_contents($root.'/app/action-center.php');
$home=(string)file_get_contents($root.'/home.php');
$feed=(string)file_get_contents($root.'/app/feed.php');
$conversations=(string)file_get_contents($root.'/app/conversations.php');
$activity=(string)file_get_contents($root.'/app/unified-activity.php');
$chromeFeed=(string)file_get_contents($root.'/extension/sidepanel-feed.js');
$chromeCss=(string)file_get_contents($root.'/extension/sidepanel.css');
$chromeHtml=(string)file_get_contents($root.'/extension/sidepanel.html');
$workspace=(string)file_get_contents($root.'/assets/js/workspace-state.js');

p36c(str_contains($cognitive,'function cognitive_feed_compose_from_items'),'Cognitive Feed can compose from one already-collected request snapshot');
p36c(str_contains($actions,'?array $cognitiveBase=null')&&str_contains($actions,'$cognitiveBase??cognitive_feed_items'),'Action Center can reuse the same Cognitive snapshot');
p36c(str_contains($home,'$cognitiveBase=cognitive_feed_items')&&str_contains($home,'cognitive_feed_compose_from_items($cognitiveBase')&&str_contains($home,'action_center_compose($pdo,$u,$chatTeams,60,$cognitiveBase)'),'Home performs one Cognitive collection pass for Now and Action Center');
p36c(substr_count($home,'cognitive_feed_items($pdo,$u,$chatTeams,false)')===1,'Home does not duplicate the expensive Cognitive collection call');

p36c(str_contains($feed,'$limit=max(1,min(30,$limit))'),'Annotation feed hard-caps each page at 30 posts');
p36c(str_contains($conversations,'$limit=max(1,min(100,$limit))'),'conversation history hard-caps each request at 100 messages');
p36c(str_contains($activity,'$limit=max(5,min(120,$limit))'),'workspace activity hard-caps each request at 120 items');
p36c(str_contains($actions,'$limit=max(4,min(100,$limit))'),'Action Center hard-caps each request at 100 actions');
p36c(str_contains($chromeFeed,'.slice(0,8)'),'Chrome renders only the first eight Action Center cards');
p36c(str_contains($chromeHtml,'pageMoreSentinel')&&str_contains($chromeHtml,'followingMoreSentinel'),'Chrome page and Following feeds retain incremental pagination sentinels');

p36c(str_contains($chromeHtml,'role="tablist"')&&substr_count($chromeHtml,'role="tabpanel"')>=6,'Chrome workspace retains accessible tab/tabpanel semantics');
p36c(str_contains($chromeFeed,"setAttribute('aria-selected'"),'Chrome tab switching maintains aria-selected state');
p36c(str_contains($chromeCss,'grid-template-columns:repeat(6,minmax(0,1fr))'),'all six Chrome workspace tabs fit the hardened one-row layout');
p36c(str_contains((string)file_get_contents($root.'/assets/css/app.css'),'@media(max-width:')||str_contains((string)file_get_contents($root.'/assets/css/app.css'),'@media (max-width:'),'website CSS retains responsive mobile breakpoints');
p36c(str_contains($home,'teamChatMobileToggle')&&str_contains($home,'aria-controls="team-chat"'),'Home retains the mobile Team Chat drawer control');
p36c(str_contains($home,'role="log" aria-live="polite"')&&str_contains((string)file_get_contents($root.'/research-project.php'),'role="log" aria-live="polite"'),'Team/Agent message streams retain live-region accessibility');

foreach(['app/annotation-ui.php','settings.php'] as $path)p36c(str_contains((string)file_get_contents($root.'/'.$path),'annotation-cards.js?v=36.0'),"$path uses the Phase 36 Annotation client cache tag");
foreach(['workspace-state.js?v=36.0','agent-chat.js?v=36.0','team-chat.js?v=36.0'] as $needle)p36c(str_contains($home,$needle),"Home uses current release cache tag: $needle");
p36c(str_contains((string)file_get_contents($root.'/activity.php'),'activity.js?v=36.0')&&str_contains((string)file_get_contents($root.'/activity.php'),'workspace-state.js?v=36.0'),'Activity uses current release cache tags');
p36c(str_contains((string)file_get_contents($root.'/action-center.php'),'action-center.js?v=36.0')&&str_contains((string)file_get_contents($root.'/action-center.php'),'workspace-state.js?v=36.0'),'Action Center uses current release cache tags');
p36c(str_contains((string)file_get_contents($root.'/research-project.php'),'research-agent.js?v=36.0')&&str_contains((string)file_get_contents($root.'/research-project.php'),'workspace-state.js?v=36.0'),'Research workspace uses current release cache tags');

p36c(str_contains($workspace,'sessionStorage.setItem(KEY')&&!str_contains($workspace,'localStorage.setItem(KEY'),'website workspace continuity remains session-only');
p36c(str_contains((string)file_get_contents($root.'/extension/sidepanel-workspace.js'),'chrome.storage.session.set'),'Chrome workspace continuity remains session-only');
p36c(!is_file($root.'/database/migrations/20260921_036_release_hardening.sql'),'Phase 36 introduces no database migration');
foreach(['new intelligence','new ranker','shadow journey','journey_events'] as $forbidden)p36c(!str_contains(strtolower((string)file_get_contents($root.'/docs/phase-36-end-to-end-workspace-release-hardening.md')),$forbidden),"Phase 36 documentation avoids introducing another subsystem: $forbidden");

echo "Phase 36 release hardening contract suite passed.\n";
