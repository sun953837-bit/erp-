<?php

use App\Services\Bi\BiEtlService;
use App\Services\ChannelHub\RawChannelMappingService;
use App\Services\Order\ServiceOrderReconciliationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('app:health', function () {
    $this->info('php-api is healthy');
})->describe('Check php-api app health');

Artisan::command('bi:etl-refresh {--mode=stage1 : full|incremental|stage1} {--window-days=3 : incremental window in days}', function (BiEtlService $etlService) {
    $mode = strtolower((string) $this->option('mode'));
    if (! in_array($mode, ['full', 'incremental', 'stage1'], true)) {
        $this->error('invalid --mode, expected full|incremental|stage1');
        return 1;
    }

    $windowDays = max(1, min(90, (int) $this->option('window-days')));
    $result = $etlService->refresh([
        'mode' => $mode,
        'window_days' => $windowDays,
    ]);
    $this->info('BI ETL refresh completed.');
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return 0;
})->describe('Run Stage-1 BI ETL refresh (full, incremental, or stage1 strategy)');

Schedule::command('bi:etl-refresh --mode=stage1 --window-days='.max(1, (int) env('BI_ETL_INCREMENTAL_WINDOW_DAYS', 3)))
    ->cron((string) env('BI_ETL_CRON', '15 * * * *'))
    ->withoutOverlapping()
    ->when(static fn (): bool => filter_var((string) env('BI_ETL_AUTO_REFRESH_ENABLED', 'false'), FILTER_VALIDATE_BOOL));

Artisan::command('bi:etl-recover {--mode= : full|incremental|stage1} {--window-days= : incremental window in days}', function (BiEtlService $etlService) {
    $result = $etlService->recover([
        'mode' => $this->option('mode') ?: null,
        'window_days' => $this->option('window-days') ?: null,
    ]);
    $this->info('BI ETL recover completed.');
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return 0;
})->describe('Run BI ETL failure compensation/recover flow');

Schedule::command('bi:etl-recover')
    ->cron((string) env('BI_ETL_RECOVER_CRON', '25 * * * *'))
    ->withoutOverlapping()
    ->when(static fn (): bool => filter_var((string) env('BI_ETL_AUTO_RECOVER_ENABLED', 'true'), FILTER_VALIDATE_BOOL));

Artisan::command('channel:map-raw {--limit=100 : max pending rows to process per table}', function (RawChannelMappingService $service) {
    $limit = max(1, min(1000, (int) $this->option('limit')));
    $result = $service->run(['limit' => $limit]);
    $this->info('Raw channel mapping completed.');
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return 0;
})->describe('Map pending raw channel records into ERP service-order and refund tables');

Schedule::command('channel:map-raw --limit='.max(1, (int) env('RAW_MAPPING_LIMIT', 100)))
    ->cron((string) env('RAW_MAPPING_CRON', '*/2 * * * *'))
    ->withoutOverlapping()
    ->when(static fn (): bool => filter_var((string) env('RAW_MAPPING_AUTO_ENABLED', 'true'), FILTER_VALIDATE_BOOL));

Artisan::command('orders:service-reconcile {--sample-limit=50 : sample rows per mismatch category}', function (ServiceOrderReconciliationService $service) {
    $sampleLimit = max(1, min(500, (int) $this->option('sample-limit')));
    $result = $service->reconcile([
        'sample_limit' => $sampleLimit,
    ]);
    $this->info('Service order reconciliation completed.');
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return 0;
})->describe('Reconcile service_orders with canonical orders(order_type=service)');

Schedule::command('orders:service-reconcile --sample-limit='.max(1, (int) env('SERVICE_ORDER_RECON_SAMPLE_LIMIT', 50)))
    ->cron((string) env('SERVICE_ORDER_RECON_CRON', '35 2 * * *'))
    ->withoutOverlapping()
    ->when(static fn (): bool => filter_var((string) env('SERVICE_ORDER_RECON_AUTO_ENABLED', 'true'), FILTER_VALIDATE_BOOL));
