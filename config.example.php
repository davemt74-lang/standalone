<?php
return [
    'app' => [
        'name' => 'Annotated',
        'base_url' => 'https://annotated.example.com',
        'session_name' => 'annotated_session',
    ],
    'db' => [
        'dsn' => 'mysql:host=127.0.0.1;dbname=annotated;charset=utf8mb4',
        'user' => 'annotated',
        'pass' => 'change-me',
    ],
    'oauth' => [
        'google' => ['client_id' => '', 'client_secret' => '', 'redirect_uri' => ''],
        'x' => ['client_id' => '', 'client_secret' => '', 'redirect_uri' => ''],
    ],
];
