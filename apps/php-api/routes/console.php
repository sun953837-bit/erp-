<?php

use App\Services\Bi\BiEtlService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('app:health', function () {
    $this->info('php-api is healthy');
})->describe('Check php-api app health');

Artisan::command('bi:etl-refresh {--mode=full : full|incremental} {--window-days=3 : incremental window in days}', function (BiEtlService $etlService) {
    $mode = strtolower((string) $this->option('mode'));
    if (! in_array($mode, ['full', 'incremental'], true)) {
        $this->error('invalid --mode, expected full|incremental');
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
})->describe('Run Stage-1 BI ETL refresh (full or incremental)');

Schedule::command('bi:etl-refresh --mode=incremental --window-days='.max(1, (int) env('BI_ETL_INCREMENTAL_WINDOW_DAYS', 3)))
    ->cron((string) env('BI_ETL_CRON', '15 * * * *'))
    ->withoutOverlapping()
    ->when(static fn (): bool => filter_var((string) env('BI_ETL_AUTO_REFRESH_ENABLED', 'false'), FILTER_VALIDATE_BOOL));
