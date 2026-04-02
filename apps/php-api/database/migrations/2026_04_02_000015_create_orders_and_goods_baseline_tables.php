<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 64)->unique();
            $table->string('order_type', 32)->default('service');
            $table->string('platform_code', 64)->nullable();
            $table->foreignId('shop_id')->nullable()->constrained('shops')->nullOnDelete();
            $table->string('external_order_id', 128)->nullable();
            $table->unsignedBigInteger('legacy_service_order_id')->nullable()->unique();
            $table->string('subject', 255)->nullable();
            $table->string('customer_name', 128)->nullable();
            $table->string('currency', 16)->default('CNY');
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('status', 32)->default('pending');
            $table->string('delivery_mode', 32)->default('auto');
            $table->json('meta_json')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['order_type', 'status', 'created_at'], 'idx_orders_type_status_created');
            $table->index(['platform_code', 'shop_id'], 'idx_orders_platform_shop');
            $table->index(['order_type', 'external_order_id'], 'idx_orders_type_external');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('item_no', 64)->nullable();
            $table->string('item_type', 32)->default('service');
            $table->string('sku_code', 128)->nullable();
            $table->string('title', 255);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('total_price', 18, 2)->default(0);
            $table->string('currency', 16)->default('CNY');
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'item_type'], 'idx_order_items_order_type');
            $table->index('sku_code');
        });

        Schema::create('goods_order_fulfillments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('fulfillment_no', 64)->unique();
            $table->string('logistics_status', 32)->default('pending');
            $table->string('carrier', 64)->nullable();
            $table->string('tracking_no', 128)->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->index(['logistics_status', 'shipped_at'], 'idx_goods_fulfill_status_shipped');
            $table->index('tracking_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_order_fulfillments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
