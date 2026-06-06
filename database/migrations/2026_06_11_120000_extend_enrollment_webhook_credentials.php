<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollment_webhook_credentials', function (Blueprint $table) {
            if (! Schema::hasColumn('enrollment_webhook_credentials', 'product_id')) {
                $table->char('product_id', 36)->nullable()->after('name');
                $table->string('platform', 64)->nullable()->after('product_id');
                $table->string('external_product_id', 191)->nullable()->after('platform');
                $table->timestamp('last_used_at')->nullable()->after('is_active');
                $table->index(['tenant_id', 'product_id']);
            }
        });

        Schema::table('enrollment_webhook_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('enrollment_webhook_logs', 'enrollment_webhook_id')) {
                $table->unsignedBigInteger('enrollment_webhook_id')->nullable()->after('tenant_id');
                $table->index(['enrollment_webhook_id', 'processed_at']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('enrollment_webhook_logs', function (Blueprint $table) {
            if (Schema::hasColumn('enrollment_webhook_logs', 'enrollment_webhook_id')) {
                $table->dropIndex(['enrollment_webhook_id', 'processed_at']);
                $table->dropColumn('enrollment_webhook_id');
            }
        });

        Schema::table('enrollment_webhook_credentials', function (Blueprint $table) {
            if (Schema::hasColumn('enrollment_webhook_credentials', 'product_id')) {
                $table->dropIndex(['tenant_id', 'product_id']);
                $table->dropColumn(['product_id', 'platform', 'external_product_id', 'last_used_at']);
            }
        });
    }
};
