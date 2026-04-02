<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('service_orders')) {
            Schema::table('service_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('service_orders', 'customer_id')) {
                    $table->string('customer_id', 128)->nullable()->after('customer_name');
                    $table->index(['platform_code', 'customer_id'], 'idx_service_orders_platform_customer_id');
                }
            });

            DB::statement('UPDATE service_orders SET customer_id = customer_name WHERE customer_id IS NULL');
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (! Schema::hasColumn('orders', 'customer_id')) {
                    $table->string('customer_id', 128)->nullable()->after('customer_name');
                    $table->index(['order_type', 'customer_id'], 'idx_orders_type_customer_id');
                }
                if (! Schema::hasColumn('orders', 'project_id')) {
                    $table->unsignedBigInteger('project_id')->nullable()->after('legacy_service_order_id');
                    $table->index(['order_type', 'project_id'], 'idx_orders_type_project_id');
                }
                if (! Schema::hasColumn('orders', 'ticket_id')) {
                    $table->unsignedBigInteger('ticket_id')->nullable()->after('project_id');
                    $table->index(['order_type', 'ticket_id'], 'idx_orders_type_ticket_id');
                }
            });

            DB::statement('UPDATE orders SET customer_id = customer_name WHERE customer_id IS NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (Schema::hasColumn('orders', 'customer_id')) {
                    $table->dropIndex('idx_orders_type_customer_id');
                    $table->dropColumn('customer_id');
                }
                if (Schema::hasColumn('orders', 'project_id')) {
                    $table->dropIndex('idx_orders_type_project_id');
                    $table->dropColumn('project_id');
                }
                if (Schema::hasColumn('orders', 'ticket_id')) {
                    $table->dropIndex('idx_orders_type_ticket_id');
                    $table->dropColumn('ticket_id');
                }
            });
        }

        if (Schema::hasTable('service_orders')) {
            Schema::table('service_orders', function (Blueprint $table) {
                if (Schema::hasColumn('service_orders', 'customer_id')) {
                    $table->dropIndex('idx_service_orders_platform_customer_id');
                    $table->dropColumn('customer_id');
                }
            });
        }
    }
};
