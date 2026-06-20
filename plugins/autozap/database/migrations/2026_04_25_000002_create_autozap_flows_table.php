<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autozap_flows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('product_id', 64)->nullable()->index();
            $table->string('trigger_event', 255)->index();
            $table->string('name', 255);
            $table->boolean('is_active')->default(true);
            $table->json('graph_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autozap_flows');
    }
};

