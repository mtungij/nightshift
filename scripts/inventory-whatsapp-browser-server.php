<?php

use Illuminate\Http\Request;

require __DIR__.'/inventory-whatsapp-browser-bootstrap.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$asset = realpath(public_path(ltrim(urldecode($path), '/')));
if ($path !== '/' && $asset && str_starts_with($asset, public_path().DIRECTORY_SEPARATOR) && is_file($asset)) {
    return false;
}
$app->handleRequest(Request::capture());
