<?php

namespace Tests\Support;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\MySqlBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Compile the real migrations without opening any database connection. */
final class MySqlSchemaAudit
{
    public static function compile(): array
    {
        $connection = new class(fn () => throw new RuntimeException('The offline audit must never connect to a database.'), 'nightshift_offline', '', ['driver' => 'mysql', 'name' => 'nightshift_offline', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'engine' => 'InnoDB', 'prefix_indexes' => true]) extends MySqlConnection
        {
            public function isMaria(): bool
            {
                return false;
            }

            public function getServerVersion(): string
            {
                return '8.4.0';
            }

            protected function escapeString($value)
            {
                // Pretend-mode query logging also formats data-migration bindings.
                // Quote them locally so compilation never needs a PDO connection.
                return "'".str_replace(['\\', "'"], ['\\\\', "''"], $value)."'";
            }
        };
        $connection->useDefaultSchemaGrammar();
        $builder = new class($connection) extends MySqlBuilder
        {
            public array $tables = [];

            protected function build(Blueprint $blueprint): void
            {
                $table = $blueprint->getTable();
                $this->tables[$table] ??= ['columns' => [], 'indexes' => [], 'foreign_keys' => []];
                foreach ($blueprint->toSql() as $sql) {
                    $this->connection->statement($sql);
                }
                foreach ($blueprint->getColumns() as $column) {
                    $this->tables[$table]['columns'][$column->name] = $column->getAttributes();
                }
                foreach ($blueprint->getCommands() as $command) {
                    if (in_array($command->name, ['primary', 'unique', 'index'], true)) {
                        $this->tables[$table]['indexes'][$command->index] = [
                            'type' => $command->name, 'columns' => $command->columns,
                        ];
                    }
                    if ($command->name === 'foreign') {
                        $this->tables[$table]['foreign_keys'][$command->index] = [
                            'columns' => $command->columns, 'foreign_table' => (string) $command->on,
                            'foreign_columns' => (array) $command->references,
                            'on_delete' => $command->onDelete,
                        ];
                    }
                }
            }

            public function hasColumn($table, $column): bool
            {
                return isset($this->tables[$table]['columns'][$column]);
            }

            public function getForeignKeys($table): array
            {
                return array_values($this->tables[$table]['foreign_keys'] ?? []);
            }
        };
        $manager = DB::getFacadeRoot();
        $originalDefault = $manager->getDefaultConnection();
        $originalSchema = Schema::getFacadeRoot();
        config(['database.connections.nightshift_offline' => ['driver' => 'mysql']]);
        $manager->extend('nightshift_offline', fn () => $connection);
        $manager->setDefaultConnection('nightshift_offline');
        Schema::swap($builder);
        $migrations = glob(database_path('migrations/*.php'));
        sort($migrations);
        try {
            $queries = $connection->pretend(function () use ($migrations) {
                foreach ($migrations as $path) {
                    (require $path)->up();
                }
            });

            return [
                'target' => 'MySQL 8.4 / InnoDB / utf8mb4',
                'execution' => 'offline grammar compilation; no server execution',
                'migrations' => array_map('basename', $migrations),
                'tables' => $builder->tables,
                'sql' => array_column($queries, 'query'),
            ];
        } finally {
            $manager->setDefaultConnection($originalDefault);
            $manager->purge('nightshift_offline');
            $manager->forgetExtension('nightshift_offline');
            config(['database.connections.nightshift_offline' => null]);
            Schema::swap($originalSchema);
        }
    }

    public static function inspect(array $schema): array
    {
        $errors = [];
        $identifiers = [];
        $foreignNames = [];
        $money = [];
        $json = [];
        $statuses = [];
        $foreignCount = 0;
        $indexCount = 0;
        foreach ($schema['tables'] as $table => $definition) {
            $identifiers[] = $table;
            foreach ($definition['columns'] as $name => $column) {
                $identifiers[] = $name;
                if (in_array($column['type'], ['float', 'double'], true)) {
                    $errors[] = "Approximate numeric column: {$table}.{$name}";
                }
                if ($column['type'] === 'decimal' && $column['places'] === 2) {
                    $money[] = $table.'.'.$name;
                    if ($column['total'] !== 16) {
                        $errors[] = "Unexpected monetary precision: {$table}.{$name}";
                    }
                }
                if ($column['type'] === 'json') {
                    $json[] = $table.'.'.$name;
                }
                if ($name === 'status' || $name === 'previous_status') {
                    $statuses[$table.'.'.$name] = $column['length'];
                }
                if (! empty($column['autoIncrement'])) {
                    $definition['indexes']['PRIMARY'] = ['type' => 'primary', 'columns' => [$name]];
                }
            }
            foreach ($definition['indexes'] as $name => $index) {
                $identifiers[] = $name;
                $indexCount++;
                $bytes = 0;
                foreach ($index['columns'] as $columnName) {
                    $column = $definition['columns'][$columnName] ?? null;
                    if (! $column) {
                        $errors[] = "Index {$name} references a missing column.";

                        continue;
                    }
                    $bytes += match ($column['type']) {
                        'string', 'char' => 4 * $column['length'],
                        'bigInteger' => 8, 'integer' => 4, 'smallInteger' => 2,
                        'tinyInteger', 'boolean' => 1, 'timestamp', 'dateTime' => 8, 'date' => 3,
                        default => 3073,
                    };
                }
                if ($bytes > 3072) {
                    $errors[] = "Index {$table}.{$name} exceeds the standard 16KiB InnoDB page key limit.";
                }
                if (count($index['columns']) > 16) {
                    $errors[] = "Index {$table}.{$name} has more than 16 key parts.";
                }
            }
            foreach ($definition['foreign_keys'] as $name => $fk) {
                $identifiers[] = $name;
                $foreignCount++;
                if (isset($foreignNames[$name])) {
                    $errors[] = "Duplicate schema-wide foreign-key name: {$name}";
                }
                $foreignNames[$name] = true;
                $parent = $schema['tables'][$fk['foreign_table']] ?? null;
                if (! $parent) {
                    $errors[] = "Missing referenced table for {$name}.";

                    continue;
                }
                $uniqueKeys = array_values(array_filter($parent['indexes'], fn ($index) => in_array($index['type'], ['primary', 'unique'], true)));
                foreach ($parent['columns'] as $columnName => $column) {
                    if (! empty($column['autoIncrement'])) {
                        $uniqueKeys[] = ['columns' => [$columnName]];
                    }
                }
                if (! array_filter($uniqueKeys, fn ($key) => $key['columns'] === $fk['foreign_columns'])) {
                    $errors[] = "Foreign key {$name} does not reference an exact primary/unique key.";
                }
                foreach ($fk['columns'] as $i => $columnName) {
                    $childColumn = $definition['columns'][$columnName] ?? [];
                    $parentColumn = $parent['columns'][$fk['foreign_columns'][$i]] ?? [];
                    if (! $childColumn || ! $parentColumn || $childColumn['type'] !== $parentColumn['type'] || ($childColumn['unsigned'] ?? false) !== ($parentColumn['unsigned'] ?? false)) {
                        $errors[] = "Foreign key {$name} has mismatched column types/signedness.";
                    }
                }
            }
        }
        $expectedStates = [
            'orders.status' => ['DRAFT', 'WAITING_FOR_ISSUE', 'ISSUED', 'PARTIALLY_PAID', 'PAID', 'CANCELLED', 'VOID_REQUESTED', 'VOIDED', 'RETURNED'],
            'void_requests.previous_status' => ['ISSUED', 'PARTIALLY_PAID', 'PAID'],
            'shifts.status' => ['OPEN', 'CLOSED'],
            'void_requests.status' => ['PENDING', 'APPROVED', 'REJECTED'],
            'stock_adjustments.status' => ['PENDING', 'APPROVED', 'REJECTED'],
            'stock_transfers.status' => ['DRAFT', 'IN_TRANSIT', 'RECEIVED'],
            'goods_receipts.status' => ['POSTED'],
        ];
        foreach ($expectedStates as $column => $states) {
            foreach ($states as $state) {
                if (strlen($state) > ($statuses[$column] ?? 0)) {
                    $errors[] = "State {$state} does not fit {$column}.";
                }
            }
        }
        foreach ($schema['sql'] as $sql) {
            if (preg_match_all('/(?:constraint|create trigger)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i', $sql, $matches)) {
                array_push($identifiers, ...$matches[1]);
            }
            if (preg_match('/\b(?:PRAGMA|AUTOINCREMENT|strftime|rowid)\b|INSERT\s+OR\s+/i', $sql)) {
                $errors[] = 'SQLite syntax in MySQL DDL: '.$sql;
            }
            if (str_starts_with($sql, 'create table') && ! str_contains($sql, 'engine = InnoDB')) {
                $errors[] = 'Table DDL does not explicitly select InnoDB.';
            }
        }
        usort($identifiers, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($identifiers as $name) {
            if (strlen($name) > 64) {
                $errors[] = "Identifier exceeds 64 characters: {$name}";
            }
        }

        return [
            'errors' => $errors, 'migration_count' => count($schema['migrations']),
            'table_count' => count($schema['tables']), 'statement_count' => count($schema['sql']),
            'foreign_key_count' => $foreignCount, 'declared_index_count' => $indexCount,
            'longest_identifier' => $identifiers[0], 'longest_identifier_length' => strlen($identifiers[0]),
            'money_columns' => $money, 'json_columns' => $json, 'status_columns' => $statuses,
            'index_assumption' => '16KiB InnoDB pages, utf8mb4 (up to 4 bytes/character)',
        ];
    }
}
