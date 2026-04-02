<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('raw_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_task_id')->nullable()->constrained('sync_tasks')->nullOnDelete();
            $table->string('platform_code', 64);
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('site_code', 64)->nullable();
            $table->string('event_key', 128)->nullable();
            $table->string('external_order_id', 128)->nullable();
            $table->json('payload_json')->nullable();
            $table->string('mapped_status', 32)->default('PENDING');
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['platform_code', 'shop_id', 'received_at']);
            $table->index('external_order_id');
            $table->index('mapped_status');
        });

        Schema::create('raw_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_task_id')->nullable()->constrained('sync_tasks')->nullOnDelete();
            $table->string('platform_code', 64);
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('site_code', 64)->nullable();
            $table->string('event_key', 128)->nullable();
            $table->string('external_refund_id', 128)->nullable();
            $table->json('payload_json')->nullable();
            $table->string('mapped_status', 32)->default('PENDING');
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['platform_code', 'shop_id', 'received_at']);
            $table->index('external_refund_id');
            $table->index('mapped_status');
        });

        Schema::create('raw_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_task_id')->nullable()->constrained('sync_tasks')->nullOnDelete();
            $table->string('platform_code', 64);
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('site_code', 64)->nullable();
            $table->string('event_key', 128)->nullable();
            $table->string('external_listing_id', 128)->nullable();
            $table->json('payload_json')->nullable();
            $table->string('mapped_status', 32)->default('PENDING');
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['platform_code', 'shop_id', 'received_at']);
            $table->index('external_listing_id');
            $table->index('mapped_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_listings');
        Schema::dropIfExists('raw_refunds');
        Schema::dropIfExists('raw_orders');
    }
};
