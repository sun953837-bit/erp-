<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dim_platform', function (Blueprint $table) {
            $table->id();
            $table->string('platform_code', 64)->unique();
            $table->string('platform_name', 128)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('dim_shop', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->unique();
            $table->string('shop_code', 64);
            $table->string('shop_name', 255);
            $table->string('platform_code', 64);
            $table->string('site_code', 64);
            $table->string('currency', 16);
            $table->string('status', 32);
            $table->timestamps();

            $table->index(['platform_code', 'status']);
        });

        Schema::create('dim_customer', function (Blueprint $table) {
            $table->id();
            $table->string('customer_name', 128)->unique();
            $table->timestamps();
        });

        Schema::create('dim_service', function (Blueprint $table) {
            $table->id();
            $table->string('service_name', 255)->unique();
            $table->timestamps();
        });

        Schema::create('dim_date', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('date_key')->unique();
            $table->date('date')->unique();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('quarter');
            $table->unsignedTinyInteger('month');
            $table->unsignedTinyInteger('day');
            $table->unsignedTinyInteger('week_of_year');
            $table->unsignedTinyInteger('day_of_week');
            $table->boolean('is_weekend')->default(false);
            $table->timestamps();

            $table->index(['year', 'month', 'day']);
        });

        Schema::create('fact_service_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_order_id')->unique();
            $table->string('order_no', 64);
            $table->string('platform_code', 64)->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('customer_name', 128)->nullable();
            $table->string('service_name', 255);
            $table->string('status', 32);
            $table->string('currency', 16);
            $table->decimal('order_amount', 18, 2)->default(0);
            $table->decimal('received_amount', 18, 2)->default(0);
            $table->decimal('unpaid_amount', 18, 2)->default(0);
            $table->unsignedInteger('date_key_created');
            $table->unsignedInteger('date_key_confirmed')->nullable();
            $table->unsignedInteger('date_key_completed')->nullable();
            $table->timestamps();

            $table->index(['platform_code', 'shop_id', 'date_key_created'], 'idx_fact_service_orders_scope');
            $table->index(['status', 'date_key_created']);
        });

        Schema::create('fact_after_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('refund_record_id')->nullable();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('service_order_id')->nullable();
            $table->string('platform_code', 64)->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('customer_name', 128)->nullable();
            $table->string('service_name', 255)->nullable();
            $table->string('refund_status', 32);
            $table->string('currency', 16);
            $table->decimal('refund_amount', 18, 2)->default(0);
            $table->unsignedInteger('date_key_refunded')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id'], 'uk_fact_after_sales_source');
            $table->index(['platform_code', 'shop_id', 'date_key_refunded'], 'idx_fact_after_sales_scope');
            $table->index(['refund_status', 'date_key_refunded']);
        });

        Schema::create('fact_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reconciliation_record_id')->unique();
            $table->unsignedBigInteger('service_order_id');
            $table->string('platform_code', 64)->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('customer_name', 128)->nullable();
            $table->string('service_name', 255)->nullable();
            $table->string('settlement_status', 32);
            $table->string('source_type', 16);
            $table->string('currency', 16);
            $table->decimal('delta_amount', 18, 2)->default(0);
            $table->unsignedInteger('date_key')->nullable();
            $table->timestamps();

            $table->index(['platform_code', 'shop_id', 'date_key'], 'idx_fact_settlements_scope');
            $table->index(['settlement_status', 'date_key']);
        });

        Schema::create('fact_project_delivery', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_type', 16);
            $table->unsignedBigInteger('delivery_id');
            $table->unsignedBigInteger('service_order_id');
            $table->string('platform_code', 64)->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('customer_name', 128)->nullable();
            $table->string('service_name', 255)->nullable();
            $table->string('delivery_status', 32);
            $table->boolean('is_closed')->default(false);
            $table->unsignedInteger('date_key_created')->nullable();
            $table->unsignedInteger('date_key_closed')->nullable();
            $table->timestamps();

            $table->unique(['delivery_type', 'delivery_id'], 'uk_fact_project_delivery_entity');
            $table->index(['platform_code', 'shop_id', 'date_key_created'], 'idx_fact_project_delivery_scope');
            $table->index(['delivery_status', 'is_closed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_project_delivery');
        Schema::dropIfExists('fact_settlements');
        Schema::dropIfExists('fact_after_sales');
        Schema::dropIfExists('fact_service_orders');
        Schema::dropIfExists('dim_date');
        Schema::dropIfExists('dim_service');
        Schema::dropIfExists('dim_customer');
        Schema::dropIfExists('dim_shop');
        Schema::dropIfExists('dim_platform');
    }
};
