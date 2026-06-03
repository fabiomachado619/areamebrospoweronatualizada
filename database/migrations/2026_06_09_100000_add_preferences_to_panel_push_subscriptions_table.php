<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel_push_subscriptions', function (Blueprint $table) {
            $table->json('preferences')->nullable()->after('user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('panel_push_subscriptions', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });
    }
};
