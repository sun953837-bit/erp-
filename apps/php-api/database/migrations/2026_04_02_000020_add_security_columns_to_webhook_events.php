<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->timestamp('request_timestamp')->nullable()->after('signature');
            $table->timestamp('received_at')->nullable()->after('request_timestamp');
            $table->index(['platform_code', 'request_timestamp'], 'idx_webhook_platform_req_ts');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropIndex('idx_webhook_platform_req_ts');
            $table->dropColumn(['request_timestamp', 'received_at']);
        });
    }
};
