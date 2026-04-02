<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_product_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('spu_id')->nullable()->constrained('products_spu')->nullOnDelete();
            $table->foreignId('sku_id')->constrained('products_sku')->cascadeOnDelete();
            $table->string('platform_code', 64);
            $table->string('site_code', 64);
            $table->string('external_listing_id', 128)->nullable();
            $table->string('external_sku_id', 128)->nullable();
            $table->string('external_status', 64)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['shop_id', 'sku_id', 'platform_code', 'site_code'],
                'uniq_shop_sku_platform_site'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_product_mappings');
    }
};
