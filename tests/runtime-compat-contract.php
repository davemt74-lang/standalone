<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/runtime-compat.php';

function ok(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException('FAIL: '.$message);
    echo "PASS: $message\n";
}

ok(function_exists('mb_strlen'),'mb_strlen is available through runtime compatibility');
ok(function_exists('mb_substr'),'mb_substr is available through runtime compatibility');
ok(function_exists('mb_strtolower'),'mb_strtolower is available through runtime compatibility');
ok(function_exists('mb_strtoupper'),'mb_strtoupper is available through runtime compatibility');
ok(function_exists('mb_stripos'),'mb_stripos is available through runtime compatibility');

ok(mb_strlen('Annotated')===9,'ASCII length is preserved');
ok(mb_strlen('café')===4,'UTF-8 length is character based');
ok(mb_substr('research',0,4)==='rese','substring fallback matches expected behavior');
ok(mb_substr('café',0,3)==='caf','UTF-8 substring is character based');
ok(mb_strtolower('ANNOTATED')==='annotated','lowercase compatibility works for ASCII identifiers');
ok(mb_strtoupper('annotated')==='ANNOTATED','uppercase compatibility works for ASCII identifiers');
ok(mb_stripos('Research Agent','agent')===9,'case-insensitive position compatibility works');

echo "Runtime compatibility contract passed.\n";
