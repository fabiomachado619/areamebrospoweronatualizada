<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('checkout_sessions', 'country_code')) {
                $table->char('country_code', 2)->nullable()->after('customer_ip');
                $table->index(['tenant_id', 'country_code', 'created_at'], 'checkout_sessions_tenant_country_idx');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'country_code')) {
                $table->char('country_code', 2)->nullable()->after('customer_ip');
                $table->index(['tenant_id', 'country_code', 'created_at'], 'orders_tenant_country_idx');
            }
        });

        Schema::create('tracking_ad_spends', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->date('spent_on');
            $table->decimal('amount', 12, 2)->default(0);
            $table->char('currency', 3)->default('BRL');
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'spent_on', 'currency'], 'tracking_ad_spends_tenant_date_currency');
        });

        Schema::create('tracking_ad_spend_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('period_key', 32);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->char('currency', 3)->default('BRL');
            $table->timestamps();

            $table->unique(['tenant_id', 'period_key', 'currency'], 'tracking_ad_overrides_tenant_period');
        });

        Schema::create('checkout_field_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('checkout_session_id')->nullable()->index();
            $table->string('session_token', 64)->index();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->uuid('product_id')->nullable()->index();
            $table->string('field_key', 64);
            $table->string('event', 32);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'field_key', 'event', 'created_at'], 'checkout_field_events_analytics_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_field_events');
        Schema::dropIfExists('tracking_ad_spend_overrides');
        Schema::dropIfExists('tracking_ad_spends');

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'country_code')) {
                $table->dropIndex('orders_tenant_country_idx');
                $table->dropColumn('country_code');
            }
        });

        Schema::table('checkout_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('checkout_sessions', 'country_code')) {
                $table->dropIndex('checkout_sessions_tenant_country_idx');
                $table->dropColumn('country_code');
            }
        });
    }
};
