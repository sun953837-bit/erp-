<?php

namespace App\Services\Bi;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class BiEtlService
{
    private const JOB_NAME = 'stage1_bi_etl';
    private const MAX_WINDOW_DAYS = 90;
    private const STAGE1_MODE = 'stage1';

    private ?string $serviceReadSourceCache = null;

    public function refresh(array $options = []): array
    {
        $requestedMode = strtolower((string) ($options['mode'] ?? self::STAGE1_MODE));
        if (! in_array($requestedMode, ['full', 'incremental', self::STAGE1_MODE], true)) {
            throw new \InvalidArgumentException('unsupported refresh mode');
        }

        $windowDays = (int) ($options['window_days'] ?? (int) config('bi.stage1.incremental_window_days', 3));
        $windowDays = max(1, min(self::MAX_WINDOW_DAYS, $windowDays));
        $runSnapshot = $this->fetchRunSnapshot();
        $resolved = $this->resolveRefreshMode($requestedMode, $windowDays, $runSnapshot);
        $effectiveMode = $resolved['effective_mode'];
        $strategyReason = $resolved['strategy_reason'];

        $startedAt = CarbonImmutable::now();
        try {
            $counts = DB::transaction(function () use ($effectiveMode, $windowDays): array {
                if ($effectiveMode === 'full') {
                    return $this->runFullRefresh();
                }
                return $this->runIncrementalRefresh($windowDays);
            });

            $finishedAt = CarbonImmutable::now();
            $quality = $this->buildQualityMetrics($counts);
            $comparisonSince = $effectiveMode === 'incremental'
                ? CarbonImmutable::now()->subDays($windowDays)->startOfDay()
                : null;
            $serviceSourceComparison = $this->buildServiceSourceComparison($comparisonSince);
            $result = [
                'mode' => $requestedMode,
                'effective_mode' => $effectiveMode,
                'strategy_reason' => $strategyReason,
                'window_days' => $effectiveMode === 'incremental' ? $windowDays : null,
                'started_at' => $startedAt->toDateTimeString(),
                'finished_at' => $finishedAt->toDateTimeString(),
                'counts' => $counts,
                'quality' => $quality,
                'service_source_comparison' => $serviceSourceComparison,
            ];
            $this->recordRun([
                'last_mode' => $requestedMode,
                'last_effective_mode' => $effectiveMode,
                'last_strategy_reason' => $strategyReason,
                'last_window_days' => $effectiveMode === 'incremental' ? $windowDays : null,
                'last_started_at' => $startedAt,
                'last_finished_at' => $finishedAt,
                'last_success_at' => $finishedAt,
                'last_counts_json' => json_encode($counts, JSON_UNESCAPED_UNICODE),
                'last_duration_ms' => max(1, (int) $finishedAt->diffInMilliseconds($startedAt)),
                'last_total_rows' => $quality['total_rows'],
                'last_zero_count_tables_json' => json_encode($quality['zero_tables'], JSON_UNESCAPED_UNICODE),
                'last_quality_score' => $quality['quality_score'],
                'consecutive_failures' => 0,
                'last_alert_level' => $quality['alert_level'],
                'last_error_message' => null,
            ]);
            $this->emitBiAlert(
                eventType: $quality['alert_level'] === 'OK' ? 'bi_etl_success' : 'bi_etl_quality_warn',
                priority: $quality['alert_level'] === 'CRITICAL' ? 1 : 2,
                title: sprintf('BI ETL %s', $quality['alert_level']),
                content: json_encode([
                    'requested_mode' => $requestedMode,
                    'effective_mode' => $effectiveMode,
                    'strategy_reason' => $strategyReason,
                    'quality' => $quality,
                    'counts' => $counts,
                    'service_source_comparison' => $serviceSourceComparison,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                payload: [
                    'mode' => $requestedMode,
                    'effective_mode' => $effectiveMode,
                    'quality' => $quality,
                    'counts' => $counts,
                    'service_source_comparison' => $serviceSourceComparison,
                ],
                dedupeKey: sprintf('bi_etl:%s:%s', $quality['alert_level'], $finishedAt->format('YmdH')),
            );
            if ((int) ($runSnapshot['consecutive_failures'] ?? 0) > 0) {
                $this->emitBiAlert(
                    eventType: 'bi_etl_recovered',
                    priority: 2,
                    title: 'BI ETL recovered',
                    content: sprintf('BI ETL recovered after %s consecutive failures', (int) $runSnapshot['consecutive_failures']),
                    payload: [
                        'recovered_from_failures' => (int) $runSnapshot['consecutive_failures'],
                        'mode' => $requestedMode,
                        'effective_mode' => $effectiveMode,
                    ],
                    dedupeKey: sprintf('bi_etl:recovered:%s', $finishedAt->format('YmdH')),
                );
            }

            return $result;
        } catch (\Throwable $e) {
            $finishedAt = CarbonImmutable::now();
            $consecutiveFailures = (int) ($runSnapshot['consecutive_failures'] ?? 0) + 1;
            $this->recordRun([
                'last_mode' => $requestedMode,
                'last_effective_mode' => $effectiveMode,
                'last_strategy_reason' => $strategyReason,
                'last_window_days' => $effectiveMode === 'incremental' ? $windowDays : null,
                'last_started_at' => $startedAt,
                'last_finished_at' => $finishedAt,
                'last_duration_ms' => max(1, (int) $finishedAt->diffInMilliseconds($startedAt)),
                'last_success_at' => null,
                'last_counts_json' => null,
                'last_total_rows' => null,
                'last_zero_count_tables_json' => null,
                'last_quality_score' => 0,
                'consecutive_failures' => $consecutiveFailures,
                'last_alert_level' => 'CRITICAL',
                'last_error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);
            $this->emitBiAlert(
                eventType: 'bi_etl_failed',
                priority: 1,
                title: 'BI ETL failed',
                content: mb_substr($e->getMessage(), 0, 1000),
                payload: [
                    'requested_mode' => $requestedMode,
                    'effective_mode' => $effectiveMode,
                    'strategy_reason' => $strategyReason,
                    'consecutive_failures' => $consecutiveFailures,
                ],
                dedupeKey: sprintf('bi_etl:failed:%s', $finishedAt->format('YmdH')),
            );
            throw $e;
        }
    }

    public function refreshAll(): array
    {
        return $this->refresh(['mode' => 'full']);
    }

    public function recover(array $options = []): array
    {
        $snapshot = $this->fetchRunSnapshot();
        $hasFailure = is_string($snapshot['last_error_message'] ?? null) && trim((string) $snapshot['last_error_message']) !== '';
        if (! $hasFailure) {
            return [
                'recovered' => false,
                'reason' => 'no_failed_run_to_recover',
                'last_run' => $snapshot,
                'generated_at' => now()->toDateTimeString(),
            ];
        }

        $recoverMode = strtolower((string) ($options['mode'] ?? config('bi.stage1.failure_recover_mode', 'full')));
        if (! in_array($recoverMode, ['full', 'incremental', self::STAGE1_MODE], true)) {
            $recoverMode = 'full';
        }
        $windowDays = (int) ($options['window_days'] ?? (int) ($snapshot['last_window_days'] ?? config('bi.stage1.incremental_window_days', 3)));
        $windowDays = max(1, min(self::MAX_WINDOW_DAYS, $windowDays));

        $refreshResult = $this->refresh([
            'mode' => $recoverMode,
            'window_days' => $windowDays,
        ]);

        return [
            'recovered' => true,
            'recovered_by' => 'bi_etl_recover',
            'recover_mode' => $recoverMode,
            'window_days' => $windowDays,
            'refresh' => $refreshResult,
        ];
    }

    public function summary(): array
    {
        $tables = [
            'dim_platform',
            'dim_shop',
            'dim_customer',
            'dim_service',
            'dim_product',
            'dim_date',
            'fact_service_orders',
            'fact_goods_orders',
            'fact_after_sales',
            'fact_settlements',
            'fact_project_delivery',
        ];

        $counts = [];
        foreach ($tables as $table) {
            if (! $this->targetSchema()->hasTable($table)) {
                $counts[$table] = 0;
                continue;
            }
            $counts[$table] = $this->targetTable($table)->count();
        }

        $lastRun = $this->fetchRunSnapshot();

        return [
            'counts' => $counts,
            'last_run' => $lastRun,
            'service_read_source' => $this->serviceReadSourceMode(),
            'service_source_comparison' => $this->buildServiceSourceComparison(null),
            'lag_seconds' => $this->calcLagSeconds($lastRun),
            'source_connection' => $this->resolvedSourceConnectionName(),
            'target_connection' => $this->resolvedTargetConnectionName(),
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    public function monitor(): array
    {
        $viewAvailable = false;
        $viewRows = [];
        try {
            $viewRows = $this->targetTable('v_bi_etl_monitor')
                ->orderByDesc('updated_at')
                ->limit(10)
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
            $viewAvailable = true;
        } catch (\Throwable) {
            $viewAvailable = false;
        }

        $recentAlerts = [];
        if ($this->sourceSchema()->hasTable('notifications')) {
            $recentAlerts = $this->sourceTable('notifications')
                ->where('biz_type', 'bi_etl')
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        }

        $lastRun = $this->fetchRunSnapshot();

        return [
            'generated_at' => now()->toDateTimeString(),
            'service_read_source' => $this->serviceReadSourceMode(),
            'service_source_comparison' => $this->buildServiceSourceComparison(null),
            'source_connection' => $this->resolvedSourceConnectionName(),
            'target_connection' => $this->resolvedTargetConnectionName(),
            'view_available' => $viewAvailable,
            'view_rows' => $viewRows,
            'last_run' => $lastRun,
            'lag_seconds' => $this->calcLagSeconds($lastRun),
            'recent_alerts' => $recentAlerts,
        ];
    }

    private function runFullRefresh(): array
    {
        $this->clearTables();

        return [
            'dim_platform' => $this->loadDimPlatform(true),
            'dim_shop' => $this->loadDimShop(true),
            'dim_customer' => $this->loadDimCustomer(true),
            'dim_service' => $this->loadDimService(true),
            'dim_product' => $this->loadDimProduct(true),
            'dim_date' => $this->loadDimDate(null, true),
            'fact_service_orders' => $this->loadFactServiceOrders(null),
            'fact_goods_orders' => $this->loadFactGoodsOrders(null),
            'fact_after_sales' => $this->loadFactAfterSales(null),
            'fact_settlements' => $this->loadFactSettlements(null),
            'fact_project_delivery' => $this->loadFactProjectDelivery(null),
        ];
    }

    private function runIncrementalRefresh(int $windowDays): array
    {
        $since = CarbonImmutable::now()->subDays($windowDays)->startOfDay();

        return [
            'dim_platform' => $this->loadDimPlatform(false),
            'dim_shop' => $this->loadDimShop(false),
            'dim_customer' => $this->loadDimCustomer(false),
            'dim_service' => $this->loadDimService(false),
            'dim_product' => $this->loadDimProduct(false),
            'dim_date' => $this->loadDimDate($since, false),
            'fact_service_orders' => $this->loadFactServiceOrders($since),
            'fact_goods_orders' => $this->loadFactGoodsOrders($since),
            'fact_after_sales' => $this->loadFactAfterSales($since),
            'fact_settlements' => $this->loadFactSettlements($since),
            'fact_project_delivery' => $this->loadFactProjectDelivery($since),
        ];
    }

    private function clearTables(): void
    {
        foreach ([
            'fact_project_delivery',
            'fact_settlements',
            'fact_after_sales',
            'fact_goods_orders',
            'fact_service_orders',
            'dim_date',
            'dim_product',
            'dim_service',
            'dim_customer',
            'dim_shop',
            'dim_platform',
        ] as $table) {
            $this->targetTable($table)->delete();
        }
    }

    private function loadDimPlatform(bool $full): int
    {
        $codes = collect()
            ->merge($this->sourceTable('shops')->whereNotNull('platform_code')->pluck('platform_code')->all())
            ->merge($this->serviceSourceTable()
                ->whereNotNull('platform_code')
                ->pluck('platform_code')
                ->all())
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values();

        $now = now();
        $rows = $codes->map(static fn (string $code) => [
            'platform_code' => $code,
            'platform_name' => strtoupper($code),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if (count($rows) === 0) {
            return 0;
        }

        if ($full) {
            $this->targetTable('dim_platform')->delete();
            $this->bulkInsert('dim_platform', $rows);
        } else {
            $this->bulkUpsert(
                'dim_platform',
                $rows,
                ['platform_code'],
                ['platform_name', 'is_active', 'updated_at']
            );
        }

        return count($rows);
    }

    private function loadDimShop(bool $full): int
    {
        $shops = $this->sourceTable('shops')
            ->select([
                'id',
                'shop_code',
                'shop_name',
                'platform_code',
                'site_code',
                'currency',
                'status',
            ])
            ->orderBy('id')
            ->get();

        if ($shops->isEmpty()) {
            return 0;
        }

        $now = now();
        $rows = $shops->map(static fn (object $shop) => [
            'shop_id' => (int) $shop->id,
            'shop_code' => (string) $shop->shop_code,
            'shop_name' => (string) $shop->shop_name,
            'platform_code' => (string) $shop->platform_code,
            'site_code' => (string) $shop->site_code,
            'currency' => (string) $shop->currency,
            'status' => (string) $shop->status,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($full) {
            $this->targetTable('dim_shop')->delete();
            $this->bulkInsert('dim_shop', $rows);
        } else {
            $this->bulkUpsert(
                'dim_shop',
                $rows,
                ['shop_id'],
                ['shop_code', 'shop_name', 'platform_code', 'site_code', 'currency', 'status', 'updated_at']
            );
        }

        return count($rows);
    }

    private function loadDimCustomer(bool $full): int
    {
        $names = $this->serviceSourceTable()
            ->whereNotNull($this->serviceSourceCustomerNameColumn())
            ->whereRaw('TRIM('.$this->serviceSourceCustomerNameColumn().') <> ""')
            ->distinct()
            ->pluck($this->serviceSourceCustomerNameColumn())
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return 0;
        }

        $now = now();
        $rows = $names->map(static fn (string $name) => [
            'customer_name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($full) {
            $this->targetTable('dim_customer')->delete();
            $this->bulkInsert('dim_customer', $rows);
        } else {
            $this->bulkUpsert(
                'dim_customer',
                $rows,
                ['customer_name'],
                ['updated_at']
            );
        }

        return count($rows);
    }

    private function loadDimService(bool $full): int
    {
        $services = $this->serviceSourceTable()
            ->whereRaw('TRIM('.$this->serviceSourceServiceNameColumn().') <> ""')
            ->distinct()
            ->pluck($this->serviceSourceServiceNameColumn())
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values();

        if ($services->isEmpty()) {
            return 0;
        }

        $now = now();
        $rows = $services->map(static fn (string $serviceName) => [
            'service_name' => $serviceName,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($full) {
            $this->targetTable('dim_service')->delete();
            $this->bulkInsert('dim_service', $rows);
        } else {
            $this->bulkUpsert(
                'dim_service',
                $rows,
                ['service_name'],
                ['updated_at']
            );
        }

        return count($rows);
    }

    private function loadDimProduct(bool $full): int
    {
        if (! $this->sourceSchema()->hasTable('products_sku')) {
            return 0;
        }

        $query = $this->sourceTable('products_sku as sku')
            ->leftJoin('products_spu as spu', 'spu.id', '=', 'sku.spu_id')
            ->select([
                'sku.id as product_id',
                'sku.spu_id',
                'sku.sku_code',
                'sku.sku_name as product_name',
                'spu.brand',
                'spu.category_name',
                'sku.status',
            ])
            ->orderBy('sku.id');

        $products = $query->get();
        if ($products->isEmpty()) {
            return 0;
        }

        $now = now();
        $rows = $products->map(static fn (object $product): array => [
            'product_id' => (int) $product->product_id,
            'spu_id' => $product->spu_id !== null ? (int) $product->spu_id : null,
            'sku_code' => $product->sku_code !== null ? (string) $product->sku_code : null,
            'product_name' => (string) $product->product_name,
            'brand' => $product->brand !== null ? (string) $product->brand : null,
            'category_name' => $product->category_name !== null ? (string) $product->category_name : null,
            'status' => (string) $product->status,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($full) {
            $this->targetTable('dim_product')->delete();
            $this->bulkInsert('dim_product', $rows);
        } else {
            $this->bulkUpsert(
                'dim_product',
                $rows,
                ['sku_code'],
                ['product_id', 'spu_id', 'product_name', 'brand', 'category_name', 'status', 'updated_at']
            );
        }

        return count($rows);
    }

    private function loadDimDate(?CarbonImmutable $since, bool $full): int
    {
        if ($full) {
            $dateCandidates = [
                $this->serviceSourceTable()->min('created_at'),
                $this->serviceSourceTable()->min('confirmed_at'),
                $this->serviceSourceTable()->min('completed_at'),
                $this->sourceTable('refund_records')->min('refunded_at'),
                $this->sourceTable('reconciliation_records')->min('created_at'),
                $this->sourceTable('projects')->min('created_at'),
                $this->sourceTable('tickets')->min('created_at'),
                $this->serviceSourceTable()->max('created_at'),
                $this->serviceSourceTable()->max('confirmed_at'),
                $this->serviceSourceTable()->max('completed_at'),
                $this->sourceTable('refund_records')->max('refunded_at'),
                $this->sourceTable('reconciliation_records')->max('created_at'),
                $this->sourceTable('projects')->max('created_at'),
                $this->sourceTable('tickets')->max('created_at'),
            ];

            $start = null;
            $end = null;
            foreach ($dateCandidates as $candidate) {
                if ($candidate === null) {
                    continue;
                }
                $parsed = CarbonImmutable::parse((string) $candidate)->startOfDay();
                if ($start === null || $parsed->lt($start)) {
                    $start = $parsed;
                }
                if ($end === null || $parsed->gt($end)) {
                    $end = $parsed;
                }
            }

            if ($start === null || $end === null) {
                $today = CarbonImmutable::now()->startOfDay();
                $start = $today;
                $end = $today;
            }
        } else {
            $start = ($since ?? CarbonImmutable::now())->startOfDay();
            $end = CarbonImmutable::now()->startOfDay();
            if ($end->lt($start)) {
                $end = $start;
            }
        }

        $rows = [];
        $now = now();
        for ($cursor = $start; $cursor->lte($end); $cursor = $cursor->addDay()) {
            $rows[] = [
                'date_key' => (int) $cursor->format('Ymd'),
                'date' => $cursor->toDateString(),
                'year' => (int) $cursor->year,
                'quarter' => (int) $cursor->quarter,
                'month' => (int) $cursor->month,
                'day' => (int) $cursor->day,
                'week_of_year' => (int) $cursor->weekOfYear,
                'day_of_week' => (int) $cursor->dayOfWeekIso,
                'is_weekend' => $cursor->isWeekend(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($full) {
            $this->targetTable('dim_date')->delete();
            $this->bulkInsert('dim_date', $rows);
        } else {
            $this->bulkUpsert(
                'dim_date',
                $rows,
                ['date_key'],
                ['date', 'year', 'quarter', 'month', 'day', 'week_of_year', 'day_of_week', 'is_weekend', 'updated_at']
            );
        }

        return count($rows);
    }

    private function loadFactServiceOrders(?CarbonImmutable $since): int
    {
        $useCanonical = $this->useCanonicalServiceSource();
        $changedOrderIds = null;
        if ($since !== null) {
            if ($useCanonical) {
                $changedOrderIds = collect()
                    ->merge(
                        $this->sourceTable('orders')
                            ->where('order_type', 'service')
                            ->where(function (Builder $query) use ($since): void {
                                $query->where('created_at', '>=', $since)
                                    ->orWhere('updated_at', '>=', $since)
                                    ->orWhere('confirmed_at', '>=', $since)
                                    ->orWhere('completed_at', '>=', $since);
                            })
                            ->pluck('id')
                            ->all()
                    )
                    ->merge(
                        $this->sourceTable('orders as o')
                            ->join('receivable_records as rr', 'rr.service_order_id', '=', 'o.legacy_service_order_id')
                            ->where('o.order_type', 'service')
                            ->where(function (Builder $query) use ($since): void {
                                $query->where('rr.created_at', '>=', $since)
                                    ->orWhere('rr.updated_at', '>=', $since);
                            })
                            ->pluck('o.id')
                            ->all()
                    )
                    ->filter(static fn (mixed $value): bool => $value !== null)
                    ->map(static fn (mixed $value): int => (int) $value)
                    ->unique()
                    ->values()
                    ->all();
            } else {
                $changedOrderIds = collect()
                    ->merge(
                        $this->sourceTable('service_orders')
                            ->where(function (Builder $query) use ($since): void {
                                $query->where('created_at', '>=', $since)
                                    ->orWhere('updated_at', '>=', $since)
                                    ->orWhere('confirmed_at', '>=', $since)
                                    ->orWhere('completed_at', '>=', $since);
                            })
                            ->pluck('id')
                            ->all()
                    )
                    ->merge(
                        $this->sourceTable('receivable_records')
                            ->where(function (Builder $query) use ($since): void {
                                $query->where('created_at', '>=', $since)
                                    ->orWhere('updated_at', '>=', $since);
                            })
                            ->pluck('service_order_id')
                            ->all()
                    )
                    ->filter(static fn (mixed $value): bool => $value !== null)
                    ->map(static fn (mixed $value): int => (int) $value)
                    ->unique()
                    ->values()
                    ->all();
            }

            if (count($changedOrderIds) === 0) {
                return 0;
            }
        }

        if ($useCanonical) {
            $receivedByOrder = $this->sourceTable('orders as o')
                ->leftJoin('receivable_records as rr', 'rr.service_order_id', '=', 'o.legacy_service_order_id')
                ->where('o.order_type', 'service')
                ->when($changedOrderIds !== null, function ($query) use ($changedOrderIds): void {
                    $query->whereIn('o.id', $changedOrderIds);
                })
                ->groupBy('o.id')
                ->get([
                    'o.id as source_order_id',
                    DB::raw('SUM(COALESCE(rr.received_amount, 0)) AS received_amount'),
                ])
                ->pluck('received_amount', 'source_order_id');

            $ordersQuery = $this->sourceTable('orders as o')
                ->where('o.order_type', 'service')
                ->select([
                    'o.id as source_order_id',
                    'o.order_no',
                    'o.platform_code',
                    'o.shop_id',
                    'o.customer_name',
                    'o.subject as service_name',
                    'o.status',
                    'o.currency',
                    'o.amount',
                    'o.created_at',
                    'o.confirmed_at',
                    'o.completed_at',
                ])
                ->orderBy('o.id');
        } else {
            $receivedByOrder = $this->sourceTable('receivable_records')
                ->select('service_order_id', DB::raw('SUM(received_amount) AS received_amount'))
                ->when($changedOrderIds !== null, function ($query) use ($changedOrderIds): void {
                    $query->whereIn('service_order_id', $changedOrderIds);
                })
                ->groupBy('service_order_id')
                ->get()
                ->pluck('received_amount', 'service_order_id');

            $ordersQuery = $this->sourceTable('service_orders')
                ->select([
                    'id as source_order_id',
                    'order_no',
                    'platform_code',
                    'shop_id',
                    'customer_name',
                    'service_name',
                    'status',
                    'currency',
                    'amount',
                    'created_at',
                    'confirmed_at',
                    'completed_at',
                ])
                ->orderBy('id');
        }

        if ($changedOrderIds !== null) {
            if ($useCanonical) {
                $ordersQuery->whereIn('o.id', $changedOrderIds);
            } else {
                $ordersQuery->whereIn('id', $changedOrderIds);
            }
        }
        $orders = $ordersQuery->get();

        if ($orders->isEmpty()) {
            return 0;
        }

        if ($changedOrderIds !== null) {
            $this->deleteByIds('fact_service_orders', 'service_order_id', $changedOrderIds);
        }

        $now = now();
        $rows = [];
        foreach ($orders as $order) {
            $sourceOrderId = (int) $order->source_order_id;
            $amount = round((float) $order->amount, 2);
            $received = round((float) ($receivedByOrder->get($sourceOrderId) ?? 0.0), 2);
            $rows[] = [
                'service_order_id' => $sourceOrderId,
                'order_no' => (string) $order->order_no,
                'platform_code' => $this->nullableString($order->platform_code),
                'shop_id' => $order->shop_id !== null ? (int) $order->shop_id : null,
                'customer_name' => $this->nullableString($order->customer_name),
                'service_name' => (string) $order->service_name,
                'status' => (string) $order->status,
                'currency' => (string) $order->currency,
                'order_amount' => $amount,
                'received_amount' => $received,
                'unpaid_amount' => round(max(0.0, $amount - $received), 2),
                'date_key_created' => $this->toDateKey($order->created_at) ?? (int) now()->format('Ymd'),
                'date_key_confirmed' => $this->toDateKey($order->confirmed_at),
                'date_key_completed' => $this->toDateKey($order->completed_at),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->bulkInsert('fact_service_orders', $rows);
        return count($rows);
    }

    private function loadFactGoodsOrders(?CarbonImmutable $since): int
    {
        if (! $this->sourceSchema()->hasTable('orders')) {
            return 0;
        }

        $changedOrderIds = null;
        if ($since !== null) {
            $changedOrderIds = collect()
                ->merge(
                    $this->sourceTable('orders')
                        ->where('order_type', 'goods')
                        ->where(function (Builder $query) use ($since): void {
                            $query->where('created_at', '>=', $since)
                                ->orWhere('updated_at', '>=', $since)
                                ->orWhere('confirmed_at', '>=', $since)
                                ->orWhere('completed_at', '>=', $since);
                        })
                        ->pluck('id')
                        ->all()
                )
                ->merge(
                    $this->sourceTable('order_items')
                        ->where('item_type', 'goods')
                        ->where(function (Builder $query) use ($since): void {
                            $query->where('created_at', '>=', $since)
                                ->orWhere('updated_at', '>=', $since);
                        })
                        ->pluck('order_id')
                        ->all()
                )
                ->filter(static fn (mixed $value): bool => $value !== null)
                ->map(static fn (mixed $value): int => (int) $value)
                ->unique()
                ->values()
                ->all();

            if (count($changedOrderIds) === 0) {
                return 0;
            }
        }

        $itemCountByOrder = $this->sourceTable('order_items')
            ->select([
                'order_id',
                DB::raw('SUM(quantity) AS total_quantity'),
            ])
            ->where('item_type', 'goods')
            ->when($changedOrderIds !== null, function ($query) use ($changedOrderIds): void {
                $query->whereIn('order_id', $changedOrderIds);
            })
            ->groupBy('order_id')
            ->get()
            ->pluck('total_quantity', 'order_id');

        $ordersQuery = $this->sourceTable('orders')
            ->where('order_type', 'goods')
            ->select([
                'id as goods_order_id',
                'order_no',
                'platform_code',
                'shop_id',
                'customer_name',
                'customer_id',
                'status',
                'currency',
                'amount',
                'created_at',
                'confirmed_at',
                'completed_at',
            ])
            ->orderBy('id');

        if ($changedOrderIds !== null) {
            $ordersQuery->whereIn('id', $changedOrderIds);
        }
        $orders = $ordersQuery->get();

        if ($orders->isEmpty()) {
            return 0;
        }

        if ($changedOrderIds !== null) {
            $this->deleteByIds('fact_goods_orders', 'goods_order_id', $changedOrderIds);
        }

        $now = now();
        $rows = [];
        foreach ($orders as $order) {
            $goodsOrderId = (int) $order->goods_order_id;
            $rows[] = [
                'goods_order_id' => $goodsOrderId,
                'order_no' => (string) $order->order_no,
                'platform_code' => $this->nullableString($order->platform_code),
                'shop_id' => $order->shop_id !== null ? (int) $order->shop_id : null,
                'customer_name' => $this->nullableString($order->customer_name),
                'customer_id' => $this->nullableString($order->customer_id),
                'status' => (string) $order->status,
                'currency' => (string) $order->currency,
                'order_amount' => round((float) $order->amount, 2),
                'item_count' => max(0, (int) ($itemCountByOrder->get($goodsOrderId) ?? 0)),
                'date_key_created' => $this->toDateKey($order->created_at),
                'date_key_confirmed' => $this->toDateKey($order->confirmed_at),
                'date_key_completed' => $this->toDateKey($order->completed_at),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->bulkInsert('fact_goods_orders', $rows);
        return count($rows);
    }

    private function loadFactAfterSales(?CarbonImmutable $since): int
    {
        $useCanonical = $this->useCanonicalServiceSource();
        $now = now();
        $rows = [];

        $changedRefundIds = null;
        if ($since !== null) {
            $changedRefundIds = $this->sourceTable('refund_records')
                ->where(function (Builder $query) use ($since): void {
                    $query->where('created_at', '>=', $since)
                        ->orWhere('updated_at', '>=', $since)
                        ->orWhere('refunded_at', '>=', $since);
                })
                ->pluck('id')
                ->map(static fn (mixed $value): int => (int) $value)
                ->unique()
                ->values()
                ->all();

            if (count($changedRefundIds) > 0) {
                $this->deleteBySource('fact_after_sales', 'refund_record', $changedRefundIds);
            }
        }

        if ($useCanonical) {
            $refundsQuery = $this->sourceTable('refund_records as rr')
                ->leftJoin('orders as so', function ($join): void {
                    $join->on('so.legacy_service_order_id', '=', 'rr.service_order_id')
                        ->where('so.order_type', '=', 'service');
                })
                ->select([
                    'rr.id as refund_record_id',
                    DB::raw('COALESCE(so.id, rr.service_order_id) AS source_service_order_id'),
                    'rr.amount',
                    'rr.currency',
                    'rr.status',
                    'rr.refunded_at',
                    'so.platform_code',
                    'so.shop_id',
                    'so.customer_name',
                    'so.subject as service_name',
                ])
                ->orderBy('rr.id');
        } else {
            $refundsQuery = $this->sourceTable('refund_records as rr')
                ->leftJoin('service_orders as so', 'so.id', '=', 'rr.service_order_id')
                ->select([
                    'rr.id as refund_record_id',
                    'rr.service_order_id as source_service_order_id',
                    'rr.amount',
                    'rr.currency',
                    'rr.status',
                    'rr.refunded_at',
                    'so.platform_code',
                    'so.shop_id',
                    'so.customer_name',
                    'so.service_name',
                ])
                ->orderBy('rr.id');
        }

        if ($changedRefundIds !== null) {
            if (count($changedRefundIds) === 0) {
                $refunds = collect();
            } else {
                $refundsQuery->whereIn('rr.id', $changedRefundIds);
                $refunds = $refundsQuery->get();
            }
        } else {
            $refunds = $refundsQuery->get();
        }

        foreach ($refunds as $refund) {
            $rows[] = [
                'refund_record_id' => (int) $refund->refund_record_id,
                'source_type' => 'refund_record',
                'source_id' => (int) $refund->refund_record_id,
                'service_order_id' => (int) $refund->source_service_order_id,
                'platform_code' => $this->nullableString($refund->platform_code),
                'shop_id' => $refund->shop_id !== null ? (int) $refund->shop_id : null,
                'customer_name' => $this->nullableString($refund->customer_name),
                'service_name' => $this->nullableString($refund->service_name),
                'refund_status' => (string) $refund->status,
                'currency' => (string) $refund->currency,
                'refund_amount' => round((float) $refund->amount, 2),
                'date_key_refunded' => $this->toDateKey($refund->refunded_at),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $changedOrderIds = null;
        if ($since !== null) {
            if ($useCanonical) {
                $changedOrderIds = $this->sourceTable('orders')
                    ->where('order_type', 'service')
                    ->where(function (Builder $query) use ($since): void {
                        $query->where('created_at', '>=', $since)
                            ->orWhere('updated_at', '>=', $since);
                    })
                    ->pluck('id')
                    ->map(static fn (mixed $value): int => (int) $value)
                    ->unique()
                    ->values()
                    ->all();
            } else {
                $changedOrderIds = $this->sourceTable('service_orders')
                    ->where(function (Builder $query) use ($since): void {
                        $query->where('created_at', '>=', $since)
                            ->orWhere('updated_at', '>=', $since);
                    })
                    ->pluck('id')
                    ->map(static fn (mixed $value): int => (int) $value)
                    ->unique()
                    ->values()
                    ->all();
            }

            if (count($changedOrderIds) > 0) {
                $this->deleteBySource('fact_after_sales', 'service_order_status', $changedOrderIds);
            }
        }

        if ($useCanonical) {
            $afterSaleOrdersQuery = $this->sourceTable('orders as so')
                ->where('so.order_type', 'service')
                ->where('so.status', 'after_sale')
                ->whereNotExists(function (Builder $query): void {
                    $query->select(DB::raw('1'))
                        ->from('refund_records as rr')
                        ->whereRaw('rr.service_order_id = COALESCE(so.legacy_service_order_id, so.id)');
                })
                ->select([
                    'so.id as source_service_order_id',
                    'so.platform_code',
                    'so.shop_id',
                    'so.customer_name',
                    'so.subject as service_name',
                    'so.currency',
                    'so.updated_at',
                ])
                ->orderBy('so.id');
        } else {
            $afterSaleOrdersQuery = $this->sourceTable('service_orders as so')
                ->where('so.status', 'after_sale')
                ->whereNotExists(function (Builder $query): void {
                    $query->select(DB::raw('1'))
                        ->from('refund_records as rr')
                        ->whereColumn('rr.service_order_id', 'so.id');
                })
                ->select([
                    'so.id as source_service_order_id',
                    'so.platform_code',
                    'so.shop_id',
                    'so.customer_name',
                    'so.service_name',
                    'so.currency',
                    'so.updated_at',
                ])
                ->orderBy('so.id');
        }

        if ($changedOrderIds !== null) {
            if (count($changedOrderIds) === 0) {
                $afterSaleOrders = collect();
            } else {
                $afterSaleOrdersQuery->whereIn('so.id', $changedOrderIds);
                $afterSaleOrders = $afterSaleOrdersQuery->get();
            }
        } else {
            $afterSaleOrders = $afterSaleOrdersQuery->get();
        }

        foreach ($afterSaleOrders as $order) {
            $rows[] = [
                'refund_record_id' => null,
                'source_type' => 'service_order_status',
                'source_id' => (int) $order->source_service_order_id,
                'service_order_id' => (int) $order->source_service_order_id,
                'platform_code' => $this->nullableString($order->platform_code),
                'shop_id' => $order->shop_id !== null ? (int) $order->shop_id : null,
                'customer_name' => $this->nullableString($order->customer_name),
                'service_name' => $this->nullableString($order->service_name),
                'refund_status' => 'AFTER_SALE_ONLY',
                'currency' => (string) $order->currency,
                'refund_amount' => 0.0,
                'date_key_refunded' => $this->toDateKey($order->updated_at),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->bulkInsert('fact_after_sales', $rows);
        return count($rows);
    }

    private function loadFactSettlements(?CarbonImmutable $since): int
    {
        $useCanonical = $this->useCanonicalServiceSource();
        $changedSettlementIds = null;
        if ($since !== null) {
            $changedSettlementIds = $this->sourceTable('reconciliation_records')
                ->where(function (Builder $query) use ($since): void {
                    $query->where('created_at', '>=', $since)
                        ->orWhere('updated_at', '>=', $since);
                })
                ->pluck('id')
                ->map(static fn (mixed $value): int => (int) $value)
                ->unique()
                ->values()
                ->all();

            if (count($changedSettlementIds) === 0) {
                return 0;
            }
        }

        if ($useCanonical) {
            $settlementsQuery = $this->sourceTable('reconciliation_records as rc')
                ->leftJoin('orders as so', function ($join): void {
                    $join->on('so.legacy_service_order_id', '=', 'rc.service_order_id')
                        ->where('so.order_type', '=', 'service');
                })
                ->select([
                    'rc.id as reconciliation_record_id',
                    DB::raw('COALESCE(so.id, rc.service_order_id) AS source_service_order_id'),
                    'rc.refund_record_id',
                    'rc.delta_amount',
                    'rc.currency',
                    'rc.status',
                    'rc.created_at',
                    'so.platform_code',
                    'so.shop_id',
                    'so.customer_name',
                    'so.subject as service_name',
                ])
                ->orderBy('rc.id');
        } else {
            $settlementsQuery = $this->sourceTable('reconciliation_records as rc')
                ->leftJoin('service_orders as so', 'so.id', '=', 'rc.service_order_id')
                ->select([
                    'rc.id as reconciliation_record_id',
                    'rc.service_order_id as source_service_order_id',
                    'rc.refund_record_id',
                    'rc.delta_amount',
                    'rc.currency',
                    'rc.status',
                    'rc.created_at',
                    'so.platform_code',
                    'so.shop_id',
                    'so.customer_name',
                    'so.service_name',
                ])
                ->orderBy('rc.id');
        }

        if ($changedSettlementIds !== null) {
            $settlementsQuery->whereIn('rc.id', $changedSettlementIds);
        }
        $settlements = $settlementsQuery->get();

        if ($settlements->isEmpty()) {
            return 0;
        }

        if ($changedSettlementIds !== null) {
            $this->deleteByIds('fact_settlements', 'reconciliation_record_id', $changedSettlementIds);
        }

        $now = now();
        $rows = $settlements->map(function (object $item) use ($now): array {
            return [
                'reconciliation_record_id' => (int) $item->reconciliation_record_id,
                'service_order_id' => (int) $item->source_service_order_id,
                'platform_code' => $this->nullableString($item->platform_code),
                'shop_id' => $item->shop_id !== null ? (int) $item->shop_id : null,
                'customer_name' => $this->nullableString($item->customer_name),
                'service_name' => $this->nullableString($item->service_name),
                'settlement_status' => (string) $item->status,
                'source_type' => $item->refund_record_id !== null ? 'refund' : 'payment',
                'currency' => (string) $item->currency,
                'delta_amount' => round((float) $item->delta_amount, 2),
                'date_key' => $this->toDateKey($item->created_at),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all();

        $this->bulkInsert('fact_settlements', $rows);
        return count($rows);
    }

    private function loadFactProjectDelivery(?CarbonImmutable $since): int
    {
        $useCanonical = $this->useCanonicalServiceSource();
        $rows = [];
        $now = now();

        $changedProjectIds = null;
        if ($since !== null) {
            $changedProjectIds = $this->sourceTable('projects')
                ->where(function (Builder $query) use ($since): void {
                    $query->where('created_at', '>=', $since)
                        ->orWhere('updated_at', '>=', $since);
                })
                ->pluck('id')
                ->map(static fn (mixed $value): int => (int) $value)
                ->unique()
                ->values()
                ->all();
        }

        if ($useCanonical) {
            $projectsQuery = $this->sourceTable('projects as p')
                ->leftJoin('orders as so', function ($join): void {
                    $join->on('so.legacy_service_order_id', '=', 'p.service_order_id')
                        ->where('so.order_type', '=', 'service');
                })
                ->whereNotNull('p.service_order_id')
                ->select([
                    'p.id as delivery_id',
                    DB::raw('COALESCE(so.id, p.service_order_id) AS source_service_order_id'),
                    'p.status as delivery_status',
                    'p.created_at as delivery_created_at',
                    'p.updated_at as delivery_updated_at',
                    'so.platform_code',
                    'so.shop_id',
                    'so.customer_name',
                    'so.subject as service_name',
                ])
                ->orderBy('p.id');
        } else {
            $projectsQuery = $this->sourceTable('projects as p')
                ->leftJoin('service_orders as so', 'so.id', '=', 'p.service_order_id')
                ->whereNotNull('p.service_order_id')
                ->select([
                    'p.id as delivery_id',
                    'p.service_order_id as source_service_order_id',
                    'p.status as delivery_status',
                    'p.created_at as delivery_created_at',
                    'p.updated_at as delivery_updated_at',
                    'so.platform_code',
                    'so.shop_id',
                    'so.customer_name',
                    'so.service_name',
                ])
                ->orderBy('p.id');
        }

        if ($changedProjectIds !== null) {
            if (count($changedProjectIds) === 0) {
                $projects = collect();
            } else {
                $projectsQuery->whereIn('p.id', $changedProjectIds);
                $projects = $projectsQuery->get();
            }
        } else {
            $projects = $projectsQuery->get();
        }

        foreach ($projects as $project) {
            $isClosed = in_array(strtolower((string) $project->delivery_status), ['completed', 'closed', 'done'], true);
            $rows[] = [
                'delivery_type' => 'project',
                'delivery_id' => (int) $project->delivery_id,
                'service_order_id' => (int) $project->source_service_order_id,
                'platform_code' => $this->nullableString($project->platform_code),
                'shop_id' => $project->shop_id !== null ? (int) $project->shop_id : null,
                'customer_name' => $this->nullableString($project->customer_name),
                'service_name' => $this->nullableString($project->service_name),
                'delivery_status' => (string) $project->delivery_status,
                'is_closed' => $isClosed,
                'date_key_created' => $this->toDateKey($project->delivery_created_at),
                'date_key_closed' => $isClosed ? $this->toDateKey($project->delivery_updated_at) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $changedTicketIds = null;
        if ($since !== null) {
            $changedTicketIds = $this->sourceTable('tickets')
                ->where(function (Builder $query) use ($since): void {
                    $query->where('created_at', '>=', $since)
                        ->orWhere('updated_at', '>=', $since);
                })
                ->pluck('id')
                ->map(static fn (mixed $value): int => (int) $value)
                ->unique()
                ->values()
                ->all();
        }

        if ($useCanonical) {
            $ticketsQuery = $this->sourceTable('tickets as t')
                ->leftJoin('orders as so', function ($join): void {
                    $join->on('so.legacy_service_order_id', '=', 't.service_order_id')
                        ->where('so.order_type', '=', 'service');
                })
                ->whereNotNull('t.service_order_id')
                ->select([
                    't.id as delivery_id',
                    DB::raw('COALESCE(so.id, t.service_order_id) AS source_service_order_id'),
                    't.status as delivery_status',
                    't.created_at as delivery_created_at',
                    't.updated_at as delivery_updated_at',
                    'so.platform_code',
                    'so.shop_id',
                    'so.customer_name',
                    'so.subject as service_name',
                ])
                ->orderBy('t.id');
        } else {
            $ticketsQuery = $this->sourceTable('tickets as t')
                ->leftJoin('service_orders as so', 'so.id', '=', 't.service_order_id')
                ->whereNotNull('t.service_order_id')
                ->select([
                    't.id as delivery_id',
                    't.service_order_id as source_service_order_id',
                    't.status as delivery_status',
                    't.created_at as delivery_created_at',
                    't.updated_at as delivery_updated_at',
                    'so.platform_code',
                    'so.shop_id',
                    'so.customer_name',
                    'so.service_name',
                ])
                ->orderBy('t.id');
        }

        if ($changedTicketIds !== null) {
            if (count($changedTicketIds) === 0) {
                $tickets = collect();
            } else {
                $ticketsQuery->whereIn('t.id', $changedTicketIds);
                $tickets = $ticketsQuery->get();
            }
        } else {
            $tickets = $ticketsQuery->get();
        }

        foreach ($tickets as $ticket) {
            $isClosed = in_array(strtolower((string) $ticket->delivery_status), ['closed', 'resolved', 'done'], true);
            $rows[] = [
                'delivery_type' => 'ticket',
                'delivery_id' => (int) $ticket->delivery_id,
                'service_order_id' => (int) $ticket->source_service_order_id,
                'platform_code' => $this->nullableString($ticket->platform_code),
                'shop_id' => $ticket->shop_id !== null ? (int) $ticket->shop_id : null,
                'customer_name' => $this->nullableString($ticket->customer_name),
                'service_name' => $this->nullableString($ticket->service_name),
                'delivery_status' => (string) $ticket->delivery_status,
                'is_closed' => $isClosed,
                'date_key_created' => $this->toDateKey($ticket->delivery_created_at),
                'date_key_closed' => $isClosed ? $this->toDateKey($ticket->delivery_updated_at) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($since !== null) {
            if ($changedProjectIds !== null && count($changedProjectIds) > 0) {
                $this->deleteDeliveryFacts('project', $changedProjectIds);
            }
            if ($changedTicketIds !== null && count($changedTicketIds) > 0) {
                $this->deleteDeliveryFacts('ticket', $changedTicketIds);
            }
        }

        if (count($rows) === 0) {
            return 0;
        }

        $this->bulkInsert('fact_project_delivery', $rows);
        return count($rows);
    }

    private function toDateKey(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) CarbonImmutable::parse((string) $value)->format('Ymd');
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function bulkInsert(string $table, array $rows, int $chunkSize = 500): void
    {
        if (count($rows) === 0) {
            return;
        }
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $this->targetTable($table)->insert($chunk);
        }
    }

    private function bulkUpsert(
        string $table,
        array $rows,
        array $uniqueBy,
        array $updateColumns,
        int $chunkSize = 500
    ): void {
        if (count($rows) === 0) {
            return;
        }
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $this->targetTable($table)->upsert($chunk, $uniqueBy, $updateColumns);
        }
    }

    private function deleteByIds(string $table, string $column, array $ids, int $chunkSize = 500): void
    {
        if (count($ids) === 0) {
            return;
        }
        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            $this->targetTable($table)->whereIn($column, $chunk)->delete();
        }
    }

    private function deleteDeliveryFacts(string $deliveryType, array $ids, int $chunkSize = 500): void
    {
        if (count($ids) === 0) {
            return;
        }
        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            $this->targetTable('fact_project_delivery')
                ->where('delivery_type', $deliveryType)
                ->whereIn('delivery_id', $chunk)
                ->delete();
        }
    }

    private function deleteBySource(string $table, string $sourceType, array $ids, int $chunkSize = 500): void
    {
        if (count($ids) === 0) {
            return;
        }
        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            $this->targetTable($table)
                ->where('source_type', $sourceType)
                ->whereIn('source_id', $chunk)
                ->delete();
        }
    }

    private function buildServiceSourceComparison(?CarbonImmutable $since): array
    {
        $legacyAvailable = $this->sourceSchema()->hasTable('service_orders');
        $canonicalAvailable = $this->sourceSchema()->hasTable('orders');

        $legacyCount = 0;
        $legacyAmount = 0.0;
        if ($legacyAvailable) {
            $legacySummary = $this->sourceTable('service_orders')
                ->when($since !== null, function ($query) use ($since): void {
                    $query->where('created_at', '>=', $since);
                })
                ->select([
                    DB::raw('COUNT(*) AS total_count'),
                    DB::raw('COALESCE(SUM(amount), 0) AS total_amount'),
                ])
                ->first();
            $legacyCount = (int) ($legacySummary->total_count ?? 0);
            $legacyAmount = round((float) ($legacySummary->total_amount ?? 0), 2);
        }

        $canonicalCount = 0;
        $canonicalAmount = 0.0;
        if ($canonicalAvailable) {
            $canonicalSummary = $this->sourceTable('orders')
                ->where('order_type', 'service')
                ->when($since !== null, function ($query) use ($since): void {
                    $query->where('created_at', '>=', $since);
                })
                ->select([
                    DB::raw('COUNT(*) AS total_count'),
                    DB::raw('COALESCE(SUM(amount), 0) AS total_amount'),
                ])
                ->first();
            $canonicalCount = (int) ($canonicalSummary->total_count ?? 0);
            $canonicalAmount = round((float) ($canonicalSummary->total_amount ?? 0), 2);
        }

        $linkedCount = 0;
        if ($legacyAvailable && $canonicalAvailable) {
            $linkedCount = $this->sourceTable('service_orders as so')
                ->join('orders as o', function ($join): void {
                    $join->on('o.legacy_service_order_id', '=', 'so.id')
                        ->where('o.order_type', '=', 'service');
                })
                ->when($since !== null, function ($query) use ($since): void {
                    $query->where(function (Builder $inner) use ($since): void {
                        $inner->where('so.created_at', '>=', $since)
                            ->orWhere('o.created_at', '>=', $since);
                    });
                })
                ->count();
        }

        $deltaAmount = round($canonicalAmount - $legacyAmount, 2);
        $deltaCount = $canonicalCount - $legacyCount;

        return [
            'window_since' => $since?->toDateTimeString(),
            'legacy_service_orders' => [
                'available' => $legacyAvailable,
                'count' => $legacyCount,
                'amount' => $legacyAmount,
            ],
            'canonical_orders' => [
                'available' => $canonicalAvailable,
                'count' => $canonicalCount,
                'amount' => $canonicalAmount,
            ],
            'linked_count' => $linkedCount,
            'delta_count' => $deltaCount,
            'delta_amount' => $deltaAmount,
            'count_balanced' => $deltaCount === 0,
            'amount_balanced' => abs($deltaAmount) < 0.00001,
        ];
    }

    private function calcLagSeconds(array $snapshot): ?int
    {
        $lastSuccess = trim((string) ($snapshot['last_success_at'] ?? ''));
        if ($lastSuccess === '') {
            return null;
        }

        try {
            $last = CarbonImmutable::parse($lastSuccess);
        } catch (\Throwable) {
            return null;
        }

        return max(0, (int) $last->diffInSeconds(CarbonImmutable::now()));
    }

    private function resolveRefreshMode(string $requestedMode, int $windowDays, array $runSnapshot): array
    {
        if ($requestedMode === 'full') {
            return [
                'effective_mode' => 'full',
                'strategy_reason' => 'manual_full',
                'window_days' => null,
            ];
        }
        if ($requestedMode === 'incremental') {
            return [
                'effective_mode' => 'incremental',
                'strategy_reason' => 'manual_incremental',
                'window_days' => $windowDays,
            ];
        }

        $lastSuccessRaw = $runSnapshot['last_success_at'] ?? null;
        if ($lastSuccessRaw === null || trim((string) $lastSuccessRaw) === '') {
            return [
                'effective_mode' => 'full',
                'strategy_reason' => 'stage1_bootstrap_full',
                'window_days' => null,
            ];
        }

        $maxLagHours = max(1, (int) config('bi.stage1.full_refresh_max_lag_hours', 24));
        $lastSuccess = CarbonImmutable::parse((string) $lastSuccessRaw);
        $lagHours = $lastSuccess->diffInHours(CarbonImmutable::now());
        if ($lagHours >= $maxLagHours) {
            return [
                'effective_mode' => 'full',
                'strategy_reason' => 'stage1_full_due_to_lag',
                'window_days' => null,
            ];
        }

        return [
            'effective_mode' => 'incremental',
            'strategy_reason' => 'stage1_incremental',
            'window_days' => $windowDays,
        ];
    }

    private function fetchRunSnapshot(): array
    {
        if (! $this->targetSchema()->hasTable('bi_etl_runs')) {
            return [];
        }

        $row = $this->targetTable('bi_etl_runs')
            ->where('job_name', self::JOB_NAME)
            ->first();
        if ($row === null) {
            return [];
        }

        return (array) $row;
    }

    private function buildQualityMetrics(array $counts): array
    {
        $zeroTables = [];
        $factZeroTables = [];
        $dimZeroTables = [];
        $totalRows = 0;

        foreach ($counts as $table => $count) {
            $tableName = (string) $table;
            $tableCount = (int) $count;
            $totalRows += max(0, $tableCount);
            if ($tableCount > 0) {
                continue;
            }

            $zeroTables[] = $tableName;
            if (str_starts_with($tableName, 'fact_')) {
                $factZeroTables[] = $tableName;
            } elseif (str_starts_with($tableName, 'dim_')) {
                $dimZeroTables[] = $tableName;
            }
        }

        $score = 100.0 - (20.0 * count($factZeroTables)) - (5.0 * count($dimZeroTables));
        $score = max(0.0, round($score, 2));
        $alertLevel = 'OK';
        if (count($factZeroTables) > 0) {
            $alertLevel = 'CRITICAL';
        } elseif (count($dimZeroTables) > 0) {
            $alertLevel = 'WARN';
        }

        return [
            'total_rows' => $totalRows,
            'zero_tables' => $zeroTables,
            'fact_zero_tables' => $factZeroTables,
            'dim_zero_tables' => $dimZeroTables,
            'quality_score' => $score,
            'alert_level' => $alertLevel,
        ];
    }

    private function emitBiAlert(
        string $eventType,
        int $priority,
        string $title,
        string $content,
        array $payload,
        string $dedupeKey
    ): void {
        if (! (bool) config('bi.stage1.alert_enabled', true)) {
            return;
        }
        if (! $this->sourceSchema()->hasTable('notifications')) {
            return;
        }

        $dedupeExists = $this->sourceTable('notifications')
            ->where('dedupe_key', $dedupeKey)
            ->where('created_at', '>=', now()->subHours(4))
            ->exists();
        if ($dedupeExists) {
            return;
        }

        $this->sourceTable('notifications')->insert([
            'event_type' => $eventType,
            'biz_type' => 'bi_etl',
            'biz_id' => self::JOB_NAME,
            'priority' => max(1, min(5, $priority)),
            'title' => $title,
            'content' => mb_substr($content, 0, 1000),
            'dedupe_key' => $dedupeKey,
            'delivery_status' => 'PENDING',
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'read_at' => null,
        ]);

        if ($this->sourceSchema()->hasTable('audit_logs')) {
            $this->sourceTable('audit_logs')->insert([
                'user_id' => null,
                'action' => 'bi_etl_alert_emitted',
                'biz_type' => 'bi_etl',
                'biz_id' => self::JOB_NAME,
                'request_id' => null,
                'ip' => null,
                'user_agent' => null,
                'detail_json' => json_encode([
                    'event_type' => $eventType,
                    'priority' => $priority,
                    'title' => $title,
                    'dedupe_key' => $dedupeKey,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
            ]);
        }
    }

    private function recordRun(array $payload): void
    {
        if (! $this->targetSchema()->hasTable('bi_etl_runs')) {
            return;
        }

        $now = now();
        $this->targetTable('bi_etl_runs')->upsert(
            [array_merge($payload, [
                'job_name' => self::JOB_NAME,
                'created_at' => $now,
                'updated_at' => $now,
            ])],
            ['job_name'],
            [
                'last_mode',
                'last_effective_mode',
                'last_strategy_reason',
                'last_window_days',
                'last_started_at',
                'last_finished_at',
                'last_duration_ms',
                'last_success_at',
                'last_counts_json',
                'last_total_rows',
                'last_zero_count_tables_json',
                'last_quality_score',
                'consecutive_failures',
                'last_alert_level',
                'last_error_message',
                'updated_at',
            ]
        );
    }

    private function sourceConnection(): ConnectionInterface
    {
        return DB::connection($this->resolvedSourceConnectionName());
    }

    private function targetConnection(): ConnectionInterface
    {
        return DB::connection($this->resolvedTargetConnectionName());
    }

    private function sourceTable(string $table): Builder
    {
        return $this->sourceConnection()->table($table);
    }

    private function targetTable(string $table): Builder
    {
        return $this->targetConnection()->table($table);
    }

    private function sourceSchema()
    {
        return $this->sourceConnection()->getSchemaBuilder();
    }

    private function targetSchema()
    {
        return $this->targetConnection()->getSchemaBuilder();
    }

    private function resolvedSourceConnectionName(): string
    {
        $configured = trim((string) config('bi.source_connection', ''));
        if ($configured !== '') {
            return $configured;
        }

        return (string) config('database.default', 'mysql');
    }

    private function resolvedTargetConnectionName(): string
    {
        $configured = trim((string) config('bi.target_connection', ''));
        if ($configured !== '') {
            return $configured;
        }

        return $this->resolvedSourceConnectionName();
    }

    private function useCanonicalServiceSource(): bool
    {
        return $this->serviceReadSourceMode() === 'canonical_orders';
    }

    private function serviceReadSourceMode(): string
    {
        if ($this->serviceReadSourceCache !== null) {
            return $this->serviceReadSourceCache;
        }

        $preferCanonical = (bool) config('bi.stage1.read_service_from_canonical_orders', false);
        $fallbackEnabled = (bool) config('bi.stage1.canonical_fallback_enabled', true);

        if (! $preferCanonical) {
            $this->serviceReadSourceCache = 'legacy_service_orders';
            return $this->serviceReadSourceCache;
        }

        $ordersTableExists = $this->sourceSchema()->hasTable('orders');
        if (! $ordersTableExists) {
            $this->serviceReadSourceCache = 'legacy_service_orders';
            return $this->serviceReadSourceCache;
        }

        if ($fallbackEnabled && $this->sourceSchema()->hasTable('service_orders')) {
            $hasCanonicalRows = $this->sourceTable('orders')
                ->where('order_type', 'service')
                ->exists();
            if (! $hasCanonicalRows) {
                $this->serviceReadSourceCache = 'legacy_service_orders';
                return $this->serviceReadSourceCache;
            }
        }

        $this->serviceReadSourceCache = 'canonical_orders';
        return $this->serviceReadSourceCache;
    }

    private function serviceSourceTable(): Builder
    {
        if ($this->useCanonicalServiceSource()) {
            return $this->sourceTable('orders')->where('order_type', 'service');
        }

        return $this->sourceTable('service_orders');
    }

    private function serviceSourceCustomerNameColumn(): string
    {
        return $this->useCanonicalServiceSource() ? 'customer_name' : 'customer_name';
    }

    private function serviceSourceServiceNameColumn(): string
    {
        return $this->useCanonicalServiceSource() ? 'subject' : 'service_name';
    }
}
