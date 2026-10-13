<?php

use Illuminate\Support\Env;

require __DIR__.'/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__), '.env.testing.mysql')->safeLoad();
$database = Env::get('MYSQL_VALIDATION_DATABASE', '');
$username = Env::get('MYSQL_VALIDATION_USERNAME', '');
if (! is_string($database) || ! preg_match('/^[a-zA-Z0-9_]+_(?:test|validation)$/D', $database)) {
    throw new RuntimeException('MySQL validation requires a disposable MYSQL_VALIDATION_DATABASE ending in _test or _validation.');
}
if (! is_string($username) || trim($username) === '' || strtolower(trim($username)) === 'root') {
    throw new RuntimeException('Provide a dedicated MYSQL_VALIDATION_USERNAME. Root credentials are not allowed.');
}
