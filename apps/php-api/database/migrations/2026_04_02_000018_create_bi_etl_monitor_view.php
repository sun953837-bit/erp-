<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('bi_etl_runs')) {
            return;
        }

        $this->dropViewIfExists('v_bi_etl_monitor');

        DB::statement(
            'CREATE VIEW v_bi_etl_monitor AS
            SELECT
                job_name,
                last_mode,
                last_effective_mode,
                last_strategy_reason,
                last_window_days,
                last_started_at,
                last_finished_at,
                last_success_at,
                last_duration_ms,
                last_total_rows,
                last_quality_score,
                consecutive_failures,
                last_alert_level,
                last_error_message,
                updated_at,
                CASE
                    WHEN last_error_message IS NULL OR TRIM(last_error_message) = \'\' THEN 1
                    ELSE 0
                END AS is_healthy
            FROM bi_etl_runs'
        );
    }

    public function down(): void
    {
        $this->dropViewIfExists('v_bi_etl_monitor');
    }

    private function dropViewIfExists(string $view): void
    {
        DB::statement("DROP VIEW IF EXISTS {$view}");
    }
};
