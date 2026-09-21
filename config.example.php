<?php
return [
    'app' => [
        'name' => 'Annotated',
        'base_url' => 'https://annotated.example.com',
        'session_name' => 'annotated_session',
        // 32+ random characters. Used only to encrypt secrets stored by Admin (such as LLM API keys).
        'encryption_key' => 'replace-with-a-long-random-secret',
    ],
    'db' => [
        'dsn' => 'mysql:host=127.0.0.1;dbname=annotated;charset=utf8mb4',
        'user' => 'annotated',
        'pass' => 'change-me',
    ],
    'storage' => [
        // Keep evidence outside the public web root. Ensure the PHP/worker user can read/write this directory.
        'private_root' => dirname(__DIR__) . '/annotated-private',
    ],
    'extension' => [
        // Optional hard allowlist of 32-character Chrome extension IDs.
        // Leave empty to allow any valid Chrome extension ID after the signed-in user explicitly approves pairing.
        'allowed_ids' => [],
        // Revocable extension bearer sessions expire even if they are not manually revoked.
        'session_ttl_days' => 30,
    ],
    'transcription' => [
        // Command receives {input} and {output}. It must write UTF-8 plain text to {output}.
        // Example: '/usr/local/bin/annotated-transcribe {input} {output}'
        'command' => '',
        'provider' => 'local',
        'model' => '',
    ],
    'oauth' => [
        'google' => ['client_id' => '', 'client_secret' => '', 'redirect_uri' => ''],
        'x' => ['client_id' => '', 'client_secret' => '', 'redirect_uri' => ''],
    ],
];
