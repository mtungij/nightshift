<?php

namespace Tests\Unit;

use Tests\Support\MySqlSchemaAudit;
use Tests\TestCase;

class MySqlSchemaCompatibilityTest extends TestCase
{
    public function test_real_migrations_compile_for_mysql_without_a_database_connection(): void
    {
        $default = config('database.default');
        $schema = MySqlSchemaAudit::compile();
        $audit = MySqlSchemaAudit::inspect($schema);
        $this->assertSame([], $audit['errors'], implode("\n", $audit['errors']));
        $this->assertSame($default, config('database.default'));
        $this->assertNotEmpty($audit['money_columns']);
        $this->assertLessThanOrEqual(64, $audit['longest_identifier_length']);
        $this->assertCount(29, $audit['money_columns']);
        $this->assertCount(11, $audit['json_columns']);
        foreach (['whatsapp_settings.options', 'whatsapp_recipients.branch_ids', 'whatsapp_delivery_logs.payload', 'whatsapp_delivery_logs.scope_branch_ids'] as $column) {
            $this->assertContains($column, $audit['json_columns']);
        }
        $sql = implode("\n", $schema['sql']);
        $this->assertSame(14, substr_count($sql, 'CREATE TRIGGER'));
        $this->assertStringContainsString('CHECK (quantity >= 0)', $sql);
        $this->assertStringContainsString('`issued_shift_id` bigint unsigned null', $sql);
        $this->assertSame(0, substr_count($sql, 'add `issued_shift_id`'));
    }

    public function test_audit_rejects_nonstandard_foreign_keys_and_approximate_money(): void
    {
        $schema = MySqlSchemaAudit::compile();
        $schema['tables']['orders']['columns']['total']['type'] = 'double';
        unset($schema['tables']['products']['indexes']['products_tenant_uq']);
        $schema['tables']['orders']['indexes'][str_repeat('x', 65)] = ['type' => 'index', 'columns' => ['company_id']];
        $errors = implode("\n", MySqlSchemaAudit::inspect($schema)['errors']);
        $this->assertStringContainsString('Approximate numeric column: orders.total', $errors);
        $this->assertStringContainsString('does not reference an exact primary/unique key', $errors);
        $this->assertStringContainsString('Identifier exceeds 64 characters', $errors);
    }

    public function test_mysql_validation_connection_is_separate_and_uses_innodb(): void
    {
        $connection = config('database.connections.mysql_validation');
        $this->assertSame('mysql', $connection['driver']);
        $this->assertSame('InnoDB', $connection['engine']);
        $this->assertTrue($connection['strict']);
        $this->assertSame('REPEATABLE READ', $connection['isolation_level']);
        $this->assertSame('+03:00', $connection['timezone']);
        $this->assertArrayNotHasKey('url', $connection);
        $this->assertNotSame('root', $connection['username']);
        $this->assertArrayHasKey('sqlite', config('database.connections'));
    }
}
