<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$home=(string)file_get_contents($root.'/home.php');
$schema=(string)file_get_contents($root.'/app/schema-health.php');
$upgrade=(string)file_get_contents($root.'/upgrade.php');

function assert_home_runtime(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('FAIL: '.$message);
    echo "PASS: $message\n";
}

$preflight=strpos($home,'app_schema_runtime_status(');
$conversation=strpos($home,'conversation_runtime_ready(');
$cognitive=strpos($home,'cognitive_feed_ready(');
$feed=strpos($home,'feed_annotation_rows(');

assert_home_runtime($preflight!==false,'Home performs a runtime schema preflight');
assert_home_runtime($conversation!==false&&$preflight<$conversation,'Schema preflight runs before conversation queries');
assert_home_runtime($cognitive!==false&&$preflight<$cognitive,'Schema preflight runs before cognitive-feed queries');
assert_home_runtime($feed!==false&&$preflight<$feed,'Schema preflight runs before feed queries');
assert_home_runtime(str_contains($home,"header('Location: /upgrade.php?from=home')"),'Admin is routed to the database upgrader when migrations are pending');
assert_home_runtime(str_contains($home,'catch(Throwable $e)'),'Home contains fail-closed runtime guards');
assert_home_runtime(str_contains($home,'home-optional-runtime'),'Optional Home modules log a traceable runtime incident');
assert_home_runtime(str_contains($schema,"['users','profile_image_url']"),'Schema preflight verifies the Home profile-image dependency');
assert_home_runtime(str_contains($schema,"['user_preferences','profile_visibility']"),'Schema preflight verifies Home privacy preference dependencies');
assert_home_runtime(str_contains($schema,"['user_preferences','search_visibility']"),'Schema preflight verifies Home discovery preference dependencies');
assert_home_runtime(!str_contains($schema,'migration_prepare_tables('),'Runtime schema preflight remains read-only');
assert_home_runtime(str_contains($upgrade,"try{\n    migration_prepare_tables($pdo);"),'Database upgrader catches initial migration inventory failures');

echo "Home runtime hardening contract passed.\n";
