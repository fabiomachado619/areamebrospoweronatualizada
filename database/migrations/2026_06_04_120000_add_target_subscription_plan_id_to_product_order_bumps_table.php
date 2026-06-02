<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_order_bumps', function (Blueprint $table) {
            $table->foreignId('target_subscription_plan_id')
                ->nullable()
                ->after('target_product_offer_id')
                ->constrained('subscription_plans')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_order_bumps', function (Blueprint $table) {
            $table->dropForeign(['target_subscription_plan_id']);
            $table->dropColumn('target_subscription_plan_id');
        });
    }
};
