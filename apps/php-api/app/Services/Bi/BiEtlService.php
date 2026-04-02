<?php

namespace App\Services\Bi;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BiEtlService
{
    private const JOB_NAME = 'stage1_bi_etl';
    private const MAX_WINDOW_DAYS = 90;

    public function refresh(array $options = []): array
    {
        $mode = strtolower((string) ($options['mode'] ?? 'full'));
        if (! in_array($mode, ['full', 'incremental'], true)) {
            throw new \InvalidArgumentException('unsupported refresh mode');
        }

        $windowDays = (int) ($options['window_days'] ?? 3);
        $windowDays = max(1, min(self::MAX_WINDOW_DAYS, $windowDays));

        $startedAt = CarbonImmutable::now();
        try {
            $counts = DB::transaction(function () use ($mode, $windowDays): array {
                if ($mode === 'full') {
                    return $this->runFullRefresh();
                }
                return $this->runIncrementalRefresh($windowDays);
            });

            $finishedAt = CarbonImmutable::now();
            $result = [
                'mode' => $mode,
                'window_days' => $mode === 'incremental' ? $windowDays : null,
                'started_at' => $startedAt->toDateTimeString(),
                'finished_at' => $finishedAt->toDateTimeString(),
                'counts' => $counts,
            ];
            $this->recordRun([
                'last_mode' => $mode,
                'last_window_days' => $mode === 'incremental' ? $windowDays : null,
                'last_started_at' => $startedAt,
                'last_finished_at' => $finishedAt,
                'last_success_at' => $finishedAt,
                'last_counts_json' => json_encode($counts, JSON_UNESCAPED_UNICODE),
                'last_error_message' => null,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $finishedAt = CarbonImmutable::now();
            $this->recordRun([
                'last_mode' => $mode,
                'last_window_days' => $mode === 'incremental' ? $windowDays : null,
                'last_started_at' => $startedAt,
                'last_finished_at' => $finishedAt,
                'last_success_at' => null,
                'last_counts_json' => null,
                'last_error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);
            throw $e;
        }
    }

    public function refreshAll(): array
    {
        return $this->refresh(['mode' => 'full']);
    }

    public function summary(): array
    {
        $tables = [
            'dim_platform',
            'dim_shop',
            'dim_customer',
            'dim_service',
            'dim_date',
            'fact_service_orders',
            'fact_after_sales',
            'fact_settlements',
            'fact_project_delivery',
        ];

        $counts = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $counts[$table] = 0;
                continue;
            }
            $counts[$table] = DB::table($table)->count();
        }

        $lastRun = null;
        if (Schema::hasTable('bi_etl_runs')) {
            $lastRun = DB::table('bi_etl_runs')
                ->where('job_name', self::JOB_NAME)
                ->first();
        }

        return [
            'counts' => $counts,
            'last_run' => $lastRun,
            'generated_at' => now()->toDateTimeString(),
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
            'dim_date' => $this->loadDimDate(null, true),
            'fact_service_orders' => $this->loadFactServiceOrders(null),
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
            'dim_date' => $this->loadDimDate($since, false),
            'fact_service_orders' => $this->loadFactServiceOrders($since),
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
            'fact_service_orders',
            'dim_date',
            'dim_service',
            'dim_customer',
            'dim_shop',
            'dim_platform',
        ] as $table) {
            DB::table($table)->delete();
        }
    }

    private function loadDimPlatform(bool $full): int
    {
        $codes = collect()
            ->merge(DB::table('shops')->whereNotNull('platform_code')->pluck('platform_code')->all())
            ->merge(DB::table('service_orders')->whereNotNull('platform_code')->pluck('platform_code')->all())
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
            DB::table('dim_platform')->delete();
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
        $shops = DB::table('shops')
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
            DB::table('dim_shop')->delete();
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
        $names = DB::table('service_orders')
            ->whereNotNull('customer_name')
            ->whereRaw('TRIM(customer_name) <> ""')
            ->distinct()
            ->pluck('customer_name')
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
            DB::table('dim_customer')->delete();
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
        $services = DB::table('service_orders')
            ->whereRaw('TRIM(service_name) <> ""')
            ->distinct()
            ->pluck('service_name')
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
            DB::table('dim_service')->delete();
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

    private function loadDimDate(?CarbonImmutable $since, bool $full): int
    {
        if ($full) {
            $dateCandidates = [
                DB::table('service_orders')->min('created_at'),
                DB::table('service_orders')->min('confirmed_at'),
                DB::table('service_orders')->min('completed_at'),
                DB::table('refund_records')->min('refunded_at'),
                DB::table('reconciliation_records')->min('created_at'),
                DB::table('projects')->min('created_at'),
                DB::table('tickets')->min('created_at'),
                DB::table('service_orders')->max('created_at'),
                DB::table('service_orders')->max('confirmed_at'),
                DB::table('service_orders')->max('completed_at'),
                DB::table('refund_records')->max('refunded_at'),
                DB::table('reconciliation_records')->max('created_at'),
                DB::table('projects')->max('created_at'),
                DB::table('tickets')->max('created_at'),
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
            DB::table('dim_date')->delete();
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
        $changedOrderIds = null;
        if ($since !== null) {
            $changedOrderIds = collect()
                ->merge(
                    DB::table('service_orders')
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
                    DB::table('receivable_records')
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

            if (count($changedOrderIds) === 0) {
                return 0;
            }
        }

        $receivedByOrder = DB::table('receivable_records')
            ->select('service_order_id', DB::raw('SUM(received_amount) AS received_amount'))
            ->when($changedOrderIds !== null, function ($query) use ($changedOrderIds) {
                $query->whereIn('service_order_id', $changedOrderIds);
            })
            ->groupBy('service_order_id')
            ->get()
            ->pluck('received_amount', 'service_order_id');

        $ordersQuery = DB::table('service_orders')
            ->select([
                'id',
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
        if ($changedOrderIds !== null) {
            $ordersQuery->whereIn('id', $changedOrderIds);
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
            $amount = round((float) $order->amount, 2);
            $received = round((float) ($receivedByOrder->get($order->id) ?? 0.0), 2);
            $rows[] = [
                'service_order_id' => (int) $order->id,
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

    private function loadFactAfterSales(?CarbonImmutable $since): int
    {
        $now = now();
        $rows = [];

        $changedRefundIds = null;
        if ($since !== null) {
            $changedRefundIds = DB::table('refund_records')
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

        $refundsQuery = DB::table('refund_records as rr')
            ->leftJoin('service_orders as so', 'so.id', '=', 'rr.service_order_id')
            ->select([
                'rr.id as refund_record_id',
                'rr.service_order_id',
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
                'service_order_id' => (int) $refund->service_order_id,
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
            $changedOrderIds = DB::table('service_orders')
                ->where(function (Builder $query) use ($since): void {
                    $query->where('created_at', '>=', $since)
                        ->orWhere('updated_at', '>=', $since);
                })
                ->pluck('id')
                ->map(static fn (mixed $value): int => (int) $value)
                ->unique()
                ->values()
                ->all();

            if (count($changedOrderIds) > 0) {
                $this->deleteBySource('fact_after_sales', 'service_order_status', $changedOrderIds);
            }
        }

        $afterSaleOrdersQuery = DB::table('service_orders as so')
            ->where('so.status', 'after_sale')
            ->whereNotExists(function (Builder $query): void {
                $query->select(DB::raw('1'))
                    ->from('refund_records as rr')
                    ->whereColumn('rr.service_order_id', 'so.id');
            })
            ->select([
                'so.id',
                'so.platform_code',
                'so.shop_id',
                'so.customer_name',
                'so.service_name',
                'so.currency',
                'so.updated_at',
            ])
            ->orderBy('so.id');
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
                'source_id' => (int) $order->id,
                'service_order_id' => (int) $order->id,
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
        $changedSettlementIds = null;
        if ($since !== null) {
            $changedSettlementIds = DB::table('reconciliation_records')
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

        $settlementsQuery = DB::table('reconciliation_records as rc')
            ->leftJoin('service_orders as so', 'so.id', '=', 'rc.service_order_id')
            ->select([
                'rc.id as reconciliation_record_id',
                'rc.service_order_id',
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
                'service_order_id' => (int) $item->service_order_id,
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
        $rows = [];
        $now = now();

        $changedProjectIds = null;
        if ($since !== null) {
            $changedProjectIds = DB::table('projects')
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

        $projectsQuery = DB::table('projects as p')
            ->leftJoin('service_orders as so', 'so.id', '=', 'p.service_order_id')
            ->whereNotNull('p.service_order_id')
            ->select([
                'p.id as delivery_id',
                'p.service_order_id',
                'p.status as delivery_status',
                'p.created_at as delivery_created_at',
                'p.updated_at as delivery_updated_at',
                'so.platform_code',
                'so.shop_id',
                'so.customer_name',
                'so.service_name',
            ])
            ->orderBy('p.id');
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
                'service_order_id' => (int) $project->service_order_id,
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
            $changedTicketIds = DB::table('tickets')
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

        $ticketsQuery = DB::table('tickets as t')
            ->leftJoin('service_orders as so', 'so.id', '=', 't.service_order_id')
            ->whereNotNull('t.service_order_id')
            ->select([
                't.id as delivery_id',
                't.service_order_id',
                't.status as delivery_status',
                't.created_at as delivery_created_at',
                't.updated_at as delivery_updated_at',
                'so.platform_code',
                'so.shop_id',
                'so.customer_name',
                'so.service_name',
            ])
            ->orderBy('t.id');
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
                'service_order_id' => (int) $ticket->service_order_id,
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
            DB::table($table)->insert($chunk);
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
            DB::table($table)->upsert($chunk, $uniqueBy, $updateColumns);
        }
    }

    private function deleteByIds(string $table, string $column, array $ids, int $chunkSize = 500): void
    {
        if (count($ids) === 0) {
            return;
        }
        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            DB::table($table)->whereIn($column, $chunk)->delete();
        }
    }

    private function deleteDeliveryFacts(string $deliveryType, array $ids, int $chunkSize = 500): void
    {
        if (count($ids) === 0) {
            return;
        }
        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            DB::table('fact_project_delivery')
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
            DB::table($table)
                ->where('source_type', $sourceType)
                ->whereIn('source_id', $chunk)
                ->delete();
        }
    }

    private function recordRun(array $payload): void
    {
        if (! Schema::hasTable('bi_etl_runs')) {
            return;
        }

        $now = now();
        DB::table('bi_etl_runs')->upsert(
            [array_merge($payload, [
                'job_name' => self::JOB_NAME,
                'created_at' => $now,
                'updated_at' => $now,
            ])],
            ['job_name'],
            [
                'last_mode',
                'last_window_days',
                'last_started_at',
                'last_finished_at',
                'last_success_at',
                'last_counts_json',
                'last_error_message',
                'updated_at',
            ]
        );
    }
}
