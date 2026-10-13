<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('currency', 3)->default('TZS');
            $t->string('timezone')->default('Africa/Dar_es_Salaam');
            $t->json('settings')->nullable();
            $t->timestamps();
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
        });
        Schema::create('permission_role', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('role_id')->nullable()->constrained()->restrictOnDelete();
            $t->boolean('active')->default(true);
            $t->unique(['company_id', 'id'], 'users_company_id_unique');
        });
        $create = function (string $name, bool $branch, Closure $fields) {
            Schema::create($name, function (Blueprint $t) use ($name, $branch, $fields) {
                $t->id();
                $t->foreignId('company_id')->constrained()->restrictOnDelete();
                if ($branch) {
                    $t->unsignedBigInteger('branch_id');
                }
                $fields($t);
                $t->timestamps();
                $t->unique(['company_id', 'id'], substr($name, 0, 20).'_tenant_uq');
                if ($branch) {
                    $t->index(['company_id', 'branch_id', 'created_at'], substr($name, 0, 20).'_scope_idx');
                    $t->foreign(['company_id', 'branch_id'], substr($name, 0, 20).'_branch_fk')->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
                }
            });
        };
        $ref = function (Blueprint $t, string $column, string $table, bool $nullable = false) {
            $c = $t->unsignedBigInteger($column);
            if ($nullable) {
                $c->nullable();
            }
            $t->foreign(['company_id', $column], 'fk_'.substr($t->getTable(), 0, 15).'_'.substr($column, 0, 15))->references(['company_id', 'id'])->on($table)->restrictOnDelete();
        };
        $user = function (Blueprint $t, string $column) use ($ref) {
            $ref($t, $column, 'users');
        };
        $create('branches', false, function ($t) {
            $t->string('name');
            $t->string('address')->nullable();
            $t->boolean('active')->default(true);
            $t->unique(['company_id', 'name']);
        });
        Schema::create('branch_user', function (Blueprint $t) use ($ref) {
            $t->unsignedBigInteger('company_id');
            $ref($t, 'branch_id', 'branches');
            $ref($t, 'user_id', 'users');
            $t->primary(['company_id', 'branch_id', 'user_id']);
        });
        $create('categories', false, function ($t) {
            $t->string('name');
            $t->unique(['company_id', 'name']);
        });
        $create('units', false, function ($t) {
            $t->string('name');
            $t->unique(['company_id', 'name']);
        });
        $create('stock_locations', true, function ($t) {
            $t->string('name');
            $t->string('type', 10);
            $t->boolean('active')->default(true);
            $t->unique(['company_id', 'branch_id', 'name'], 'location_name_uq');
        });
        $create('products', false, function ($t) use ($ref) {
            $ref($t, 'category_id', 'categories');
            $ref($t, 'base_unit_id', 'units');
            $t->string('name');
            $t->string('sku');
            $t->string('barcode')->nullable();
            $t->string('brand')->nullable();
            $t->decimal('purchase_cost', 16, 2);
            $t->decimal('minimum_stock', 18, 6)->default(0);
            $t->string('image_url')->nullable();
            $t->string('visual_style')->default('');
            $t->boolean('active')->default(true);
            $t->unique(['company_id', 'sku']);
            $t->index(['company_id', 'category_id', 'active'], 'products_category_idx');
        });
        $create('product_units', false, function ($t) use ($ref) {
            $ref($t, 'product_id', 'products');
            $ref($t, 'unit_id', 'units');
            $t->decimal('conversion_quantity', 18, 6);
            $t->decimal('selling_price', 16, 2);
            $t->unique(['company_id', 'product_id', 'unit_id'], 'product_unit_uq');
        });
        $create('stocks', true, function ($t) use ($ref) {
            $ref($t, 'stock_location_id', 'stock_locations');
            $ref($t, 'product_id', 'products');
            $t->decimal('quantity', 18, 6)->default(0);
            $t->unique(['company_id', 'stock_location_id', 'product_id'], 'stock_location_product_uq');
        });
        $create('suppliers', false, function ($t) {
            $t->string('name');
            $t->string('contact')->nullable();
            $t->string('category')->nullable();
        });
        $create('service_tables', true, function ($t) {
            $t->string('name');
            $t->string('area');
            $t->unsignedSmallInteger('seats')->default(4);
            $t->json('reservation')->nullable();
            $t->unique(['company_id', 'branch_id', 'name'], 'service_table_name_uq');
        });
        $create('shifts', true, function ($t) use ($ref, $user) {
            $user($t, 'user_id');
            $ref($t, 'stock_location_id', 'stock_locations');
            $t->string('number');
            $t->string('type', 10);
            $t->string('status', 10)->default('OPEN');
            $t->timestamp('opened_at');
            $t->timestamp('closed_at')->nullable();
            foreach (['opening_cash', 'expected_cash', 'actual_cash', 'cash_difference'] as $c) {
                $t->decimal($c, 16, 2)->default(0);
            } $t->text('closing_notes')->nullable();
            $t->text('override_reason')->nullable();
            $t->unsignedBigInteger('authorized_by')->nullable();
            $t->timestamp('override_at')->nullable();
            $t->unique(['company_id', 'number']);
            $t->index(['company_id', 'user_id', 'status'], 'shift_user_status_idx');
        });
        $create('orders', true, function ($t) use ($ref, $user) {
            $ref($t, 'stock_location_id', 'stock_locations');
            $user($t, 'waiter_id');
            $ref($t, 'service_table_id', 'service_tables', true);
            $ref($t, 'shift_id', 'shifts');
            $t->string('invoice_number');
            foreach (['subtotal', 'discount', 'return_total', 'total', 'amount_paid', 'balance', 'credit_amount'] as $c) {
                $t->decimal($c, 16, 2)->default(0);
            } $t->string('status', 25)->default('DRAFT');
            $user($t, 'created_by');
            $ref($t, 'issued_by', 'users', true);
            $ref($t, 'issued_shift_id', 'shifts', true);
            $t->timestamp('issued_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->unique(['company_id', 'invoice_number'], 'order_invoice_uq');
            $t->index(['company_id', 'branch_id', 'status', 'issued_at'], 'order_status_date_idx');
        });
        $create('order_items', true, function ($t) use ($ref) {
            $ref($t, 'order_id', 'orders');
            $ref($t, 'product_id', 'products');
            $ref($t, 'product_unit_id', 'product_units');
            $t->string('product_name');
            $t->string('unit_name');
            foreach (['quantity', 'base_quantity', 'conversion_quantity', 'returned_quantity'] as $c) {
                $t->decimal($c, 18, 6)->default(0);
            } foreach (['unit_price', 'purchase_cost', 'total', 'discount_share', 'returned_value'] as $c) {
                $t->decimal($c, 16, 2)->default(0);
            }
        });
        $create('payments', true, function ($t) use ($ref, $user) {
            $ref($t, 'shift_id', 'shifts');
            $t->string('number');
            $t->string('method', 25);
            $t->decimal('amount', 16, 2);
            $t->boolean('is_credit')->default(false);
            $user($t, 'received_by');
            $t->string('external_reference')->nullable();
            $t->unique(['company_id', 'number']);
        });
        $create('payment_allocations', true, function ($t) use ($ref) {
            $ref($t, 'payment_id', 'payments');
            $ref($t, 'order_id', 'orders');
            $t->decimal('amount', 16, 2);
            $t->unique(['company_id', 'payment_id', 'order_id'], 'payment_order_uq');
        });
        $create('returns', true, function ($t) use ($ref, $user) {
            $ref($t, 'order_id', 'orders');
            $ref($t, 'shift_id', 'shifts');
            $t->string('number');
            $t->text('reason');
            $t->decimal('total', 16, 2);
            $user($t, 'created_by');
            $t->unique(['company_id', 'number']);
        });
        $create('return_items', true, function ($t) use ($ref) {
            $ref($t, 'return_id', 'returns');
            $ref($t, 'order_item_id', 'order_items');
            $t->decimal('quantity', 18, 6);
            $t->decimal('base_quantity', 18, 6);
            $t->decimal('value', 16, 2);
        });
        $create('void_requests', true, function ($t) use ($ref, $user) {
            $ref($t, 'order_id', 'orders');
            $t->string('number');
            $t->text('reason');
            $t->string('status', 10)->default('PENDING');
            $t->string('previous_status', 25);
            $user($t, 'requested_by');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_reason')->nullable();
            $t->unique(['company_id', 'number']);
        });
        $create('goods_receipts', true, function ($t) use ($ref, $user) {
            $ref($t, 'supplier_id', 'suppliers');
            $ref($t, 'stock_location_id', 'stock_locations');
            $t->string('number');
            $t->string('supplier_invoice');
            $t->date('received_on');
            $t->decimal('total_cost', 16, 2);
            $t->string('status', 10)->default('POSTED');
            $user($t, 'created_by');
            $t->unique(['company_id', 'number']);
            $t->unique(['company_id', 'supplier_id', 'supplier_invoice'], 'grn_supplier_invoice_uq');
        });
        $create('goods_receipt_items', true, function ($t) use ($ref) {
            $ref($t, 'goods_receipt_id', 'goods_receipts');
            $ref($t, 'product_id', 'products');
            $ref($t, 'product_unit_id', 'product_units');
            foreach (['quantity', 'base_quantity'] as $c) {
                $t->decimal($c, 18, 6);
            } foreach (['unit_cost', 'total_cost'] as $c) {
                $t->decimal($c, 16, 2);
            }
        });
        $create('stock_transfers', true, function ($t) use ($ref, $user) {
            $ref($t, 'source_location_id', 'stock_locations');
            $ref($t, 'destination_location_id', 'stock_locations');
            $t->string('number');
            $t->string('status', 15)->default('DRAFT');
            $t->text('notes')->nullable();
            $user($t, 'created_by');
            $t->unsignedBigInteger('received_by')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->unique(['company_id', 'number']);
        });
        $create('stock_transfer_items', true, function ($t) use ($ref) {
            $ref($t, 'stock_transfer_id', 'stock_transfers');
            $ref($t, 'product_id', 'products');
            $ref($t, 'product_unit_id', 'product_units');
            $t->decimal('quantity', 18, 6);
            $t->decimal('base_quantity', 18, 6);
        });
        $create('stock_adjustments', true, function ($t) use ($ref, $user) {
            $ref($t, 'stock_location_id', 'stock_locations');
            $t->string('number');
            $t->string('reason', 25);
            $t->text('notes');
            $t->string('status', 10)->default('PENDING');
            $user($t, 'requested_by');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_reason')->nullable();
            $t->unique(['company_id', 'number']);
        });
        $create('stock_adjustment_items', true, function ($t) use ($ref) {
            $ref($t, 'stock_adjustment_id', 'stock_adjustments');
            $ref($t, 'product_id', 'products');
            $ref($t, 'product_unit_id', 'product_units');
            $t->decimal('quantity', 18, 6);
            $t->decimal('base_quantity', 18, 6);
            $t->decimal('cost', 16, 2);
        });
        $create('stock_movements', true, function ($t) use ($ref, $user) {
            $ref($t, 'stock_location_id', 'stock_locations');
            $ref($t, 'product_id', 'products');
            $ref($t, 'shift_id', 'shifts', true);
            $t->string('movement_type', 30);
            $t->decimal('quantity', 18, 6);
            $t->decimal('balance_after', 18, 6);
            $t->decimal('unit_cost', 16, 2);
            $t->string('reference_type', 35);
            $t->unsignedBigInteger('reference_id');
            $t->unsignedBigInteger('reversal_of')->nullable();
            $user($t, 'created_by');
            $t->index(['company_id', 'reference_type', 'reference_id'], 'movement_source_idx');
        });
        $create('waiter_ledger_entries', true, function ($t) use ($ref, $user) {
            $user($t, 'waiter_id');
            $ref($t, 'shift_id', 'shifts');
            $ref($t, 'order_id', 'orders');
            $ref($t, 'payment_id', 'payments', true);
            $ref($t, 'return_id', 'returns', true);
            $t->string('entry_type', 15);
            $t->string('reference_type', 35);
            $t->unsignedBigInteger('reference_id');
            $t->decimal('debit', 16, 2)->default(0);
            $t->decimal('credit', 16, 2)->default(0);
            $t->text('description');
            $user($t, 'created_by');
            $t->index(['company_id', 'waiter_id', 'created_at'], 'waiter_balance_idx');
        });
        $create('audit_logs', false, function ($t) {
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('user_name');
            $t->string('action', 40);
            $t->string('module', 30);
            $t->string('reference_type', 40);
            $t->string('reference_id');
            $t->json('old_values');
            $t->json('new_values');
            $t->string('ip', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->index(['company_id', 'branch_id', 'created_at'], 'audit_scope_idx');
        });
        $create('notifications', true, function ($t) {
            $t->string('type', 40);
            $t->string('title');
            $t->text('message');
            $t->string('screen', 30);
            $t->json('read_by')->nullable();
        });
        Schema::create('document_sequences', function ($t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('type', 8);
            $t->unsignedBigInteger('value')->default(0);
            $t->unique(['company_id', 'type']);
        });
        Schema::create('idempotency_keys', function ($t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('key', 80);
            $t->string('action', 60);
            $t->string('payload_hash', 64);
            $t->json('response');
            $t->timestamps();
            $t->unique(['company_id', 'key']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE stocks ADD CONSTRAINT stock_quantity_nonnegative CHECK (quantity >= 0)');
            DB::statement('ALTER TABLE product_units ADD CONSTRAINT conversion_positive CHECK (conversion_quantity > 0 AND selling_price >= 0)');
            DB::statement('ALTER TABLE orders ADD CONSTRAINT order_amounts_valid CHECK (balance >= 0 AND amount_paid >= 0 AND total >= 0)');
        }

        foreach (['audit_logs', 'waiter_ledger_entries', 'stock_movements', 'payments', 'payment_allocations', 'return_items', 'goods_receipt_items'] as $table) {
            if (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$table}_immutable_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable transaction history'");
                DB::unprepared("CREATE TRIGGER {$table}_immutable_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable transaction history'");
            }
        }
    }

    public function down(): void
    {
        foreach (['idempotency_keys', 'document_sequences', 'notifications', 'audit_logs', 'waiter_ledger_entries', 'stock_movements', 'stock_adjustment_items', 'stock_adjustments', 'stock_transfer_items', 'stock_transfers', 'goods_receipt_items', 'goods_receipts', 'void_requests', 'return_items', 'returns', 'payment_allocations', 'payments', 'order_items', 'orders', 'shifts', 'service_tables', 'suppliers', 'stocks', 'product_units', 'products', 'stock_locations', 'units', 'categories', 'branch_user', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function ($t) {
            $t->dropForeign(['company_id']);
            $t->dropForeign(['role_id']);
            $t->dropUnique('users_company_id_unique');
            $t->dropColumn(['company_id', 'role_id', 'active']);
        });
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('companies');
    }
};
