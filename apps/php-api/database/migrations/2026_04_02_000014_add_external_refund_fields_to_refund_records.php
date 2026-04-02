<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('refund_records', function (Blueprint $table) {
            $table->string('platform_code', 64)->nullable()->after('payment_record_id');
            $table->string('external_refund_id', 128)->nullable()->after('platform_code');
            $table->index(['platform_code', 'external_refund_id'], 'idx_refund_records_platform_external_refund');
        });
    }

    public function down(): void
    {
        Schema::table('refund_records', function (Blueprint $table) {
            $table->dropIndex('idx_refund_records_platform_external_refund');
            $table->dropColumn(['platform_code', 'external_refund_id']);
        });
    }
};
