<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('trading_name')->nullable();
            $table->string('business_type', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('tin', 40)->nullable();
            $table->string('vrn', 40)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('country', 100)->default('Tanzania');
            $table->string('default_language', 40)->default('English');
            $table->string('receipt_header')->nullable();
            $table->string('receipt_footer')->nullable();
            $table->boolean('active')->default(true);
            $table->string('subscription_status', 30)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('registration_key', 64)->nullable()->unique();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('phone', 40)->nullable();
        });
        Schema::table('branches', function (Blueprint $table) {
            $table->string('code', 30)->nullable();
            $table->unique(['company_id', 'code'], 'branches_company_code_unique');
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('icon', 40)->default('box');
            $table->string('color', 7)->default('#956015');
        });
    }

    public function down(): void
    {
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn(['active', 'sort_order', 'icon', 'color']));
        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique('branches_company_code_unique');
            $table->dropColumn('code');
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['first_name', 'last_name', 'phone']));
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
            $table->dropUnique(['registration_key']);
            $table->dropColumn(['trading_name', 'business_type', 'address', 'city', 'tin', 'vrn', 'logo_path', 'country', 'default_language', 'receipt_header', 'receipt_footer', 'active', 'subscription_status', 'registration_key']);
        });
    }
};
