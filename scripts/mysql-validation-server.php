<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Dedicated development router: fail closed before serving application requests.
// Run: php -S 127.0.0.1:8884 -t public scripts/mysql-validation-server.php
require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql_validation' || DB::connection()->getDatabaseName() !== 'nightshift_validation' || DB::getDriverName() !== 'mysql') {
    http_response_code(503);
    exit('Validation server requires nightshift_validation on mysql_validation.');
}
$identity = DB::selectOne('SELECT DATABASE() AS db, CURRENT_USER() AS account');
if ($identity->db !== 'nightshift_validation' || ! str_starts_with($identity->account, 'nightshift_user@')) {
    http_response_code(503);
    exit('Unexpected validation database/account.');
}
header('X-Nightshift-Validation-Database: '.$identity->db);
header('X-Nightshift-Validation-Driver: mysql');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$asset = realpath(public_path(ltrim(urldecode($path), '/')));
if ($path !== '/' && $asset && str_starts_with($asset, public_path().DIRECTORY_SEPARATOR) && is_file($asset)) {
    return false;
}
$app->handleRequest(Request::capture());
