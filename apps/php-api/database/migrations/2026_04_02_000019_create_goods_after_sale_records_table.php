<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('goods_after_sale_records', function (Blueprint $table) {
            $table->id();
            $table->string('after_sale_no', 64)->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->string('external_after_sale_id', 128)->nullable();
            $table->string('after_sale_type', 32)->default('refund');
            $table->string('status', 32)->default('OPEN');
            $table->string('reason', 255)->nullable();
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('currency', 16)->default('CNY');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status'], 'idx_goods_after_sales_order_status');
            $table->index(['external_after_sale_id'], 'idx_goods_after_sales_external');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_after_sale_records');
    }
};
