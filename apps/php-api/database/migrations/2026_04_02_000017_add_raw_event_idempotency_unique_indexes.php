<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['raw_orders', 'raw_refunds', 'raw_listings', 'raw_services'] as $table) {
            $this->deduplicateByEventKey($table);
        }

        Schema::table('raw_orders', function (Blueprint $table) {
            $table->unique(['sync_task_id', 'event_key'], 'uk_raw_orders_task_event');
        });

        Schema::table('raw_refunds', function (Blueprint $table) {
            $table->unique(['sync_task_id', 'event_key'], 'uk_raw_refunds_task_event');
        });

        Schema::table('raw_listings', function (Blueprint $table) {
            $table->unique(['sync_task_id', 'event_key'], 'uk_raw_listings_task_event');
        });

        Schema::table('raw_services', function (Blueprint $table) {
            $table->unique(['sync_task_id', 'event_key'], 'uk_raw_services_task_event');
        });
    }

    public function down(): void
    {
        Schema::table('raw_services', function (Blueprint $table) {
            $table->dropUnique('uk_raw_services_task_event');
        });

        Schema::table('raw_listings', function (Blueprint $table) {
            $table->dropUnique('uk_raw_listings_task_event');
        });

        Schema::table('raw_refunds', function (Blueprint $table) {
            $table->dropUnique('uk_raw_refunds_task_event');
        });

        Schema::table('raw_orders', function (Blueprint $table) {
            $table->dropUnique('uk_raw_orders_task_event');
        });
    }

    private function deduplicateByEventKey(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $duplicates = DB::table($table)
            ->select([
                'sync_task_id',
                'event_key',
                DB::raw('MIN(id) as keep_id'),
            ])
            ->whereNotNull('sync_task_id')
            ->whereNotNull('event_key')
            ->groupBy('sync_task_id', 'event_key')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $row) {
            DB::table($table)
                ->where('sync_task_id', (int) $row->sync_task_id)
                ->where('event_key', (string) $row->event_key)
                ->where('id', '<>', (int) $row->keep_id)
                ->delete();
        }
    }
};
