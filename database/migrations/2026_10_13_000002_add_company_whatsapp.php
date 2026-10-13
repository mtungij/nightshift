<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('device_id', 100)->nullable()->unique();
            $t->boolean('provisioned')->default(false);
            $t->string('connection_status', 30)->default('NOT_CONFIGURED');
            $t->string('jid')->nullable();
            $t->timestamp('last_checked_at')->nullable();
            $t->timestamp('last_health_queued_at')->nullable();
            $t->unsignedBigInteger('audit_cursor')->default(0);
            $t->json('options')->nullable();
            $t->timestamps();
        });
        Schema::create('whatsapp_recipients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('phone', 20);
            $t->string('label', 80)->nullable();
            $t->boolean('all_branches')->default(false);
            $t->json('branch_ids');
            $t->boolean('daily_report')->default(true);
            $t->boolean('shift_reports')->default(true);
            $t->boolean('critical_alerts')->default(true);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'phone']);
        });
        Schema::create('whatsapp_delivery_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('recipient_id')->nullable()->constrained('whatsapp_recipients')->nullOnDelete();
            $t->string('recipient_name');
            $t->string('recipient_phone', 20);
            $t->string('message_type', 40);
            $t->string('reference_type', 80)->nullable();
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('idempotency_key', 64);
            $t->text('message_preview')->nullable();
            $t->text('message')->nullable();
            $t->json('payload');
            $t->json('scope_branch_ids');
            $t->string('device_id', 100)->nullable();
            $t->string('status', 15)->default('QUEUED');
            $t->unsignedSmallInteger('attempt_count')->default(0);
            $t->string('provider_message_id')->nullable();
            $t->string('provider_status', 50)->nullable();
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->string('error_message', 500)->nullable();
            foreach (['queued_at', 'sent_at', 'failed_at', 'next_attempt_at', 'last_dispatched_at', 'sending_at'] as $column) {
                $t->timestamp($column)->nullable();
            }
            $t->timestamps();
            $t->unique(['company_id', 'idempotency_key'], 'whatsapp_delivery_dedupe');
            $t->index(['company_id', 'status', 'queued_at']);
        });
        Schema::create('whatsapp_alert_states', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('state_key', 100);
            $t->boolean('triggered')->default(false);
            $t->decimal('last_value', 20, 6)->default(0);
            $t->timestamp('last_alert_at')->nullable();
            $t->unsignedInteger('generation')->default(0);
            $t->timestamps();
            $t->unique(['company_id', 'state_key']);
        });
        // Add new grants without replacing any existing role permissions.
        foreach (config('whatsapp.permissions') as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name]);
        }
        foreach (['Super Admin', 'Company Admin / Owner', 'Manager'] as $roleName) {
            $role = DB::table('roles')->where('name', $roleName)->value('id');
            if (! $role) {
                continue;
            }
            $grants = $roleName === 'Manager' ? config('whatsapp.manager_permissions') : config('whatsapp.permissions');
            foreach (DB::table('permissions')->whereIn('name', $grants)->pluck('id') as $permission) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_alert_states');
        Schema::dropIfExists('whatsapp_delivery_logs');
        Schema::dropIfExists('whatsapp_recipients');
        Schema::dropIfExists('whatsapp_settings');
    }
};
