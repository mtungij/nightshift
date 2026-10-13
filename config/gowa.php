<?php

return [
    'url' => env('GOWA_URL', 'https://notify.buildcore.site'),
    'username' => env('GOWA_USERNAME', 'admin'),
    'password' => env('GOWA_PASSWORD'),
    'webhook_secret' => env('GOWA_WEBHOOK_SECRET'),
    'connect_timeout' => 5,
    'timeout' => 20,
    // Deliberately async, even when the application's default connection is sync.
    'queue_connection' => 'database',
    'queue' => 'whatsapp',
];
