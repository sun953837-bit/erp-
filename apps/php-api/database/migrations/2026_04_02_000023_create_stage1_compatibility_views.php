<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->createApiCallLogView();
        $this->createRawCompatibilityViews();
    }

    public function down(): void
    {
        foreach ([
            'api_call_logs',
            'raw_webhook_events',
            'raw_xianyu_orders',
            'raw_xianyu_refunds',
            'raw_xianyu_listings',
            'raw_zbj_orders',
            'raw_zbj_refunds',
            'raw_zbj_services',
        ] as $view) {
            DB::statement("DROP VIEW IF EXISTS {$view}");
        }
    }

    private function createApiCallLogView(): void
    {
        DB::statement('DROP VIEW IF EXISTS api_call_logs');
        if (! Schema::hasTable('sync_receipt_logs')) {
            return;
        }

        DB::statement(
            'CREATE VIEW api_call_logs AS
            SELECT
                id,
                sync_task_id,
                request_id,
                phase,
                http_status,
                platform_code,
                endpoint,
                success,
                accepted,
                final_result,
                external_id,
                code,
                message,
                request_payload,
                response_payload,
                created_at
            FROM sync_receipt_logs'
        );
    }

    private function createRawCompatibilityViews(): void
    {
        $this->createSingleRawView(
            view: 'raw_webhook_events',
            table: 'webhook_events'
        );

        $this->createSingleRawView(
            view: 'raw_xianyu_orders',
            table: 'raw_orders',
            where: "platform_code = 'xianyu'"
        );
        $this->createSingleRawView(
            view: 'raw_xianyu_refunds',
            table: 'raw_refunds',
            where: "platform_code = 'xianyu'"
        );
        $this->createSingleRawView(
            view: 'raw_xianyu_listings',
            table: 'raw_listings',
            where: "platform_code = 'xianyu'"
        );

        $this->createSingleRawView(
            view: 'raw_zbj_orders',
            table: 'raw_orders',
            where: "platform_code = 'zbj'"
        );
        $this->createSingleRawView(
            view: 'raw_zbj_refunds',
            table: 'raw_refunds',
            where: "platform_code = 'zbj'"
        );
        $this->createSingleRawView(
            view: 'raw_zbj_services',
            table: 'raw_services',
            where: "platform_code = 'zbj'"
        );
    }

    private function createSingleRawView(string $view, string $table, ?string $where = null): void
    {
        DB::statement("DROP VIEW IF EXISTS {$view}");
        if (! Schema::hasTable($table)) {
            return;
        }

        $sql = "CREATE VIEW {$view} AS SELECT * FROM {$table}";
        if ($where !== null && trim($where) !== '') {
            $sql .= ' WHERE '.$where;
        }
        DB::statement($sql);
    }
};
