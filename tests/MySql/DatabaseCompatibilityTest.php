<?php

namespace Tests\MySql;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MySqlSchemaAudit;
use Tests\TestCase;

/** Runs only through phpunit.mysql.xml against the dedicated test database. */
class DatabaseCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_actual_mysql_columns_indexes_engines_and_session_settings(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertStringStartsWith('8.4.', DB::connection()->getServerVersion());
        $session = DB::selectOne('SELECT @@session.sql_mode AS sql_mode, @@session.time_zone AS timezone, @@session.transaction_isolation AS isolation_level');
        $this->assertStringContainsString('ONLY_FULL_GROUP_BY', $session->sql_mode);
        $this->assertStringContainsString('STRICT_TRANS_TABLES', $session->sql_mode);
        $this->assertSame('+03:00', $session->timezone);
        $this->assertSame('REPEATABLE-READ', $session->isolation_level);
        $schema = MySqlSchemaAudit::compile();
        foreach ($schema['tables'] as $table => $definition) {
            $actual = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            $this->assertSame('InnoDB', $actual->engine, $table);
        }
        $audit = MySqlSchemaAudit::inspect($schema);
        foreach ($audit['money_columns'] as $column) {
            [$table, $name] = explode('.', $column);
            $actual = DB::selectOne('SELECT DATA_TYPE AS type, NUMERIC_PRECISION AS precision_value, NUMERIC_SCALE AS scale_value FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $name]);
            $this->assertSame('decimal', $actual->type, $column);
            $this->assertSame(16, (int) $actual->precision_value, $column);
            $this->assertSame(2, (int) $actual->scale_value, $column);
        }
        $triggers = DB::select('SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()');
        $this->assertCount(14, $triggers);
        $indexes = DB::select('SELECT INDEX_NAME AS name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()');
        foreach ([...$triggers, ...$indexes] as $identifier) {
            $this->assertLessThanOrEqual(64, strlen($identifier->name));
        }
    }

    public function test_raw_updates_cannot_bypass_immutable_audit_history(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $audit = AuditLog::create(['user_id' => $user->id, 'user_name' => $user->name, 'action' => 'VALIDATION', 'module' => 'Testing', 'reference_type' => 'User', 'reference_id' => (string) $user->id, 'old_values' => [], 'new_values' => ['validated' => true]]);
        $this->assertSame(['validated' => true], $audit->fresh()->new_values);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Immutable transaction history');
        DB::table('audit_logs')->where('id', $audit->id)->update(['action' => 'CHANGED']);
    }

    public function test_database_checks_reject_negative_stock_even_through_raw_sql(): void
    {
        $this->seed(NightshiftDemoSeeder::class);
        $stock = DB::table('stocks')->first();
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('stock_quantity_nonnegative');
        DB::table('stocks')->where('id', $stock->id)->update(['quantity' => '-1']);
    }
}
