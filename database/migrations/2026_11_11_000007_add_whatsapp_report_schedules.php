<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_report_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('report_type', 30)->default('DAILY');
            $t->boolean('enabled')->default(false);
            $t->string('send_time', 5);
            $t->string('timezone', 64);
            $t->string('cutoff_time', 5)->default('05:00');
            $t->unsignedTinyInteger('weekday')->default(1);
            $t->unsignedTinyInteger('month_day')->default(1);
            $t->boolean('month_end')->default(false);
            $t->unsignedSmallInteger('grace_minutes')->default(60);
            $t->string('format', 15)->default('TEXT');
            $t->string('language', 2)->default('sw');
            $t->string('scope', 15)->default('COMPANY');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('business_unit_id')->nullable();
            $t->unsignedBigInteger('stock_location_id')->nullable();
            $t->boolean('per_branch')->default(false);
            $t->boolean('unit_breakdown')->default(true);
            $t->json('recipient_ids');
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
            $t->index(['company_id', 'enabled']);
        });
        Schema::table('whatsapp_recipients', function (Blueprint $t) {
            $t->boolean('all_business_units')->default(true);
            $t->json('business_unit_ids')->nullable();
            $t->json('enabled_types')->nullable();
            $t->string('language', 2)->nullable();
        });
        Schema::table('whatsapp_delivery_logs', function (Blueprint $t) {
            $t->unsignedBigInteger('schedule_id')->nullable()->index();
            $t->json('scope_business_unit_ids')->nullable();
            $t->json('report_data')->nullable();
            $t->string('attachment_status', 15)->default('NONE');
            $t->string('attachment_message_id')->nullable();
            $t->string('attachment_path')->nullable();
            $t->timestamp('attachment_sent_at')->nullable();
            $t->timestamp('text_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_delivery_logs', fn (Blueprint $t) => $t->dropIndex(['schedule_id']));
        Schema::table('whatsapp_delivery_logs', fn (Blueprint $t) => $t->dropColumn(['schedule_id', 'scope_business_unit_ids', 'report_data', 'attachment_status', 'attachment_message_id', 'attachment_path', 'attachment_sent_at', 'text_sent_at']));
        Schema::table('whatsapp_recipients', fn (Blueprint $t) => $t->dropColumn(['all_business_units', 'business_unit_ids', 'enabled_types', 'language']));
        Schema::dropIfExists('whatsapp_report_schedules');
    }
};
