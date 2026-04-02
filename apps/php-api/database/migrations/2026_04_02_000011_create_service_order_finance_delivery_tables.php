<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 64)->unique();
            $table->string('platform_code', 64)->nullable();
            $table->foreignId('shop_id')->nullable()->constrained('shops')->nullOnDelete();
            $table->string('external_order_id', 128)->nullable();
            $table->string('service_name', 255);
            $table->string('customer_name', 128)->nullable();
            $table->string('currency', 16)->default('CNY');
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('status', 32)->default('pending');
            $table->string('delivery_mode', 32)->default('auto');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['platform_code', 'shop_id']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('project_no', 64)->unique();
            $table->foreignId('service_order_id')->nullable()->constrained('service_orders')->nullOnDelete();
            $table->string('name', 255);
            $table->string('status', 32)->default('pending');
            $table->string('owner', 64)->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_no', 64)->unique();
            $table->foreignId('service_order_id')->nullable()->constrained('service_orders')->nullOnDelete();
            $table->string('title', 255);
            $table->string('status', 32)->default('open');
            $table->string('assignee', 64)->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('receivable_records', function (Blueprint $table) {
            $table->id();
            $table->string('receivable_no', 64)->unique();
            $table->foreignId('service_order_id')->constrained('service_orders')->cascadeOnDelete();
            $table->decimal('amount', 18, 2)->default(0);
            $table->decimal('received_amount', 18, 2)->default(0);
            $table->string('currency', 16)->default('CNY');
            $table->string('status', 32)->default('PENDING');
            $table->timestamp('due_at')->nullable();
            $table->timestamps();

            $table->index(['service_order_id', 'status']);
        });

        Schema::create('payment_records', function (Blueprint $table) {
            $table->id();
            $table->string('payment_no', 64)->unique();
            $table->foreignId('service_order_id')->constrained('service_orders')->cascadeOnDelete();
            $table->foreignId('receivable_record_id')->nullable()->constrained('receivable_records')->nullOnDelete();
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('currency', 16)->default('CNY');
            $table->timestamp('paid_at')->nullable();
            $table->string('channel', 64)->nullable();
            $table->string('reference_no', 128)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['service_order_id', 'paid_at']);
        });

        Schema::create('refund_records', function (Blueprint $table) {
            $table->id();
            $table->string('refund_no', 64)->unique();
            $table->foreignId('service_order_id')->constrained('service_orders')->cascadeOnDelete();
            $table->foreignId('payment_record_id')->nullable()->constrained('payment_records')->nullOnDelete();
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('currency', 16)->default('CNY');
            $table->string('status', 32)->default('PENDING');
            $table->string('reason', 255)->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['service_order_id', 'status']);
        });

        Schema::create('reconciliation_records', function (Blueprint $table) {
            $table->id();
            $table->string('reconciliation_no', 64)->unique();
            $table->foreignId('service_order_id')->constrained('service_orders')->cascadeOnDelete();
            $table->foreignId('receivable_record_id')->nullable()->constrained('receivable_records')->nullOnDelete();
            $table->foreignId('refund_record_id')->nullable()->constrained('refund_records')->nullOnDelete();
            $table->decimal('delta_amount', 18, 2)->default(0);
            $table->string('currency', 16)->default('CNY');
            $table->string('status', 32)->default('OPEN');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['service_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_records');
        Schema::dropIfExists('refund_records');
        Schema::dropIfExists('payment_records');
        Schema::dropIfExists('receivable_records');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('service_orders');
    }
};
