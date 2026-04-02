<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dim_product', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('spu_id')->nullable();
            $table->string('sku_code', 128)->nullable();
            $table->string('product_name', 255);
            $table->string('brand', 128)->nullable();
            $table->string('category_name', 128)->nullable();
            $table->string('status', 32)->default('ACTIVE');
            $table->timestamps();

            $table->unique(['sku_code'], 'uk_dim_product_sku_code');
            $table->index(['spu_id', 'status'], 'idx_dim_product_spu_status');
        });

        Schema::create('fact_goods_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('goods_order_id')->unique();
            $table->string('order_no', 64);
            $table->string('platform_code', 64)->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('customer_name', 128)->nullable();
            $table->string('customer_id', 128)->nullable();
            $table->string('status', 32);
            $table->string('currency', 16);
            $table->decimal('order_amount', 18, 2)->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('date_key_created')->nullable();
            $table->unsignedInteger('date_key_confirmed')->nullable();
            $table->unsignedInteger('date_key_completed')->nullable();
            $table->timestamps();

            $table->index(['platform_code', 'shop_id', 'date_key_created'], 'idx_fact_goods_orders_scope');
            $table->index(['status', 'date_key_created'], 'idx_fact_goods_orders_status_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_goods_orders');
        Schema::dropIfExists('dim_product');
    }
};
