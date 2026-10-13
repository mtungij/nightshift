<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

try {
    require __DIR__.'/../tests/bootstrap-mysql.php';
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! app()->environment('testing') || config('database.default') !== 'mysql_validation') {
        throw new RuntimeException('Use the isolated testing environment and mysql_validation connection.');
    }
    $connection = DB::connection('mysql_validation');
    $server = $connection->selectOne('SELECT VERSION() AS version, DATABASE() AS db, @@session.sql_mode AS sql_mode, @@session.time_zone AS timezone, @@session.transaction_isolation AS isolation_level, @@innodb_page_size AS page_size, @@innodb_default_row_format AS row_format, @@log_bin AS binary_logging, @@log_bin_trust_function_creators AS trusted_trigger_creators');
    if (! str_starts_with($server->version, '8.4.')) {
        throw new RuntimeException('The validation target must be MySQL 8.4.');
    }
    if (! str_contains($server->sql_mode, 'STRICT_TRANS_TABLES') || ! str_contains($server->sql_mode, 'ONLY_FULL_GROUP_BY')) {
        throw new RuntimeException('Strict SQL mode and ONLY_FULL_GROUP_BY must be enabled for this session.');
    }
    if ($server->timezone !== '+03:00' || $server->isolation_level !== 'REPEATABLE-READ') {
        throw new RuntimeException('Unexpected session timezone or transaction isolation.');
    }
    if ($server->page_size < 16384 || strtolower($server->row_format) !== 'dynamic') {
        throw new RuntimeException('The audited index bounds assume InnoDB pages of at least 16KiB and DYNAMIC row format.');
    }
    echo json_encode(['status' => 'preflight_passed', 'server' => $server, 'scope' => 'read-only server inspection; no migrations or account changes'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    // PDO connection errors can include supplied credentials; do not print them.
    fwrite(STDERR, $error instanceof PDOException || $error instanceof QueryException
        ? "MySQL preflight could not connect or inspect the dedicated database. Check its connection and database-scoped grants.\n"
        : $error->getMessage()."\n");
    exit(1);
}
