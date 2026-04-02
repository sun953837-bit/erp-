<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bi_etl_runs', function (Blueprint $table) {
            $table->string('last_effective_mode', 32)->nullable()->after('last_mode');
            $table->string('last_strategy_reason', 128)->nullable()->after('last_effective_mode');
            $table->unsignedInteger('last_duration_ms')->nullable()->after('last_finished_at');
            $table->unsignedInteger('last_total_rows')->nullable()->after('last_counts_json');
            $table->json('last_zero_count_tables_json')->nullable()->after('last_total_rows');
            $table->decimal('last_quality_score', 5, 2)->nullable()->after('last_zero_count_tables_json');
            $table->unsignedInteger('consecutive_failures')->default(0)->after('last_quality_score');
            $table->string('last_alert_level', 16)->nullable()->after('consecutive_failures');
        });
    }

    public function down(): void
    {
        Schema::table('bi_etl_runs', function (Blueprint $table) {
            $table->dropColumn([
                'last_effective_mode',
                'last_strategy_reason',
                'last_duration_ms',
                'last_total_rows',
                'last_zero_count_tables_json',
                'last_quality_score',
                'consecutive_failures',
                'last_alert_level',
            ]);
        });
    }
};
