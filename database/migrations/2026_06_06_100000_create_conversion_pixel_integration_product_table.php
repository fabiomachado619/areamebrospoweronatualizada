<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conversion_pixel_integration_product')) {
            return;
        }

        Schema::create('conversion_pixel_integration_product', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversion_pixel_integration_id');
            $table->uuid('product_id');
            $table->timestamps();

            $table->foreign('conversion_pixel_integration_id', 'conv_pix_int_fk')
                ->references('id')
                ->on('conversion_pixel_integrations')
                ->cascadeOnDelete();
            $table->foreign('product_id', 'conv_pix_prod_fk')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();
            $table->unique(
                ['conversion_pixel_integration_id', 'product_id'],
                'conv_pix_int_product_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_pixel_integration_product');
    }
};
