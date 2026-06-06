<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'phone')) {
                    $table->string('phone', 32)->nullable()->after('email');
                }
                if (! Schema::hasColumn('users', 'document')) {
                    $table->string('document', 32)->nullable()->after('phone');
                }
            });
        }

        Schema::create('enrollment_webhook_credentials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name')->default('n8n');
            $table->string('token_prefix', 12);
            $table->string('token_hash');
            $table->text('signing_secret')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('token_prefix');
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('enrollment_external_product_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('platform', 64);
            $table->string('external_product_id', 191);
            $table->char('product_id', 36);
            $table->timestamps();

            $table->unique(['tenant_id', 'platform', 'external_product_id'], 'enrollment_ext_product_unique');
            $table->index(['tenant_id', 'product_id']);
        });

        Schema::create('enrollment_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('platform', 64)->nullable();
            $table->string('event', 64)->nullable();
            $table->string('status', 64)->nullable();
            $table->string('transaction_id', 191)->nullable();
            $table->char('course_id', 36)->nullable();
            $table->char('hub_id', 36)->nullable();
            $table->string('email')->nullable();
            $table->json('payload')->nullable();
            $table->string('action', 32);
            $table->boolean('email_sent')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'platform', 'transaction_id', 'event'], 'enrollment_webhook_idempotency_lookup');
            $table->index(['tenant_id', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_webhook_logs');
        Schema::dropIfExists('enrollment_external_product_mappings');
        Schema::dropIfExists('enrollment_webhook_credentials');

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (Schema::hasColumn('users', 'document')) {
                    $table->dropColumn('document');
                }
                if (Schema::hasColumn('users', 'phone')) {
                    $table->dropColumn('phone');
                }
            });
        }
    }
};
