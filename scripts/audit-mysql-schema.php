<?php

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\MySqlSchemaAudit;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
try {
    $schema = MySqlSchemaAudit::compile();
    $result = MySqlSchemaAudit::inspect($schema);
    if (in_array('--sql', $argv, true)) {
        echo "-- Offline MySQL 8.4 compilation. Not executed on a server.\n";
        echo implode(";\n\n", $schema['sql']).";\n";
    } else {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
    exit($result['errors'] ? 1 : 0);

} catch (Throwable $error) {
    fwrite(STDERR, 'Offline audit failed: '.$error->getMessage()."\n");
    exit(1);
}
