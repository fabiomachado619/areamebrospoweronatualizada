<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_member_hub')->default(false)->after('type');
            $table->char('member_hub_product_id', 36)->nullable()->after('is_member_hub');
            $table->foreign('member_hub_product_id')
                ->references('id')
                ->on('products')
                ->nullOnDelete();
            $table->index(['tenant_id', 'is_member_hub']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['member_hub_product_id']);
            $table->dropIndex(['tenant_id', 'is_member_hub']);
            $table->dropColumn(['is_member_hub', 'member_hub_product_id']);
        });
    }
};
