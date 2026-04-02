<?php

namespace App\Services\Order;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ServiceOrderReconciliationService
{
    public function reconcile(array $options = []): array
    {
        $sampleLimit = max(1, min(500, (int) ($options['sample_limit'] ?? 50)));
        $filters = $this->resolveFilters($options);

        $serviceTotal = $this->serviceBaseQuery($filters, 'so')->count();
        $canonicalTotal = $this->canonicalBaseQuery($filters, 'o')->count();
        $linkedTotal = $this->linkedBaseQuery($filters)->count();

        $pairs = $this->linkedBaseQuery($filters)
            ->select([
                'so.id as service_order_id',
                'so.order_no as service_order_no',
                'so.platform_code as service_platform_code',
                'so.shop_id as service_shop_id',
                'so.external_order_id as service_external_order_id',
                'so.service_name as service_name',
                'so.customer_name as service_customer_name',
                'so.customer_id as service_customer_id',
                'so.currency as service_currency',
                'so.amount as service_amount',
                'so.status as service_status',
                'so.delivery_mode as service_delivery_mode',
                'so.project_id as service_project_id',
                'so.ticket_id as service_ticket_id',
                'o.id as canonical_order_id',
                'o.order_no as canonical_order_no',
                'o.platform_code as canonical_platform_code',
                'o.shop_id as canonical_shop_id',
                'o.external_order_id as canonical_external_order_id',
                'o.subject as canonical_subject',
                'o.customer_name as canonical_customer_name',
                'o.customer_id as canonical_customer_id',
                'o.currency as canonical_currency',
                'o.amount as canonical_amount',
                'o.status as canonical_status',
                'o.delivery_mode as canonical_delivery_mode',
                'o.project_id as canonical_project_id',
                'o.ticket_id as canonical_ticket_id',
                'o.meta_json as canonical_meta_json',
            ])
            ->orderBy('so.id')
            ->get();

        $serviceOrderIds = $pairs->pluck('service_order_id')->map(static fn (mixed $value): int => (int) $value)->unique()->values()->all();
        $canonicalOrderIds = $pairs->pluck('canonical_order_id')->map(static fn (mixed $value): int => (int) $value)->unique()->values()->all();
        $financeByServiceOrder = $this->buildFinanceStatsByServiceOrder($serviceOrderIds);
        $serviceItemCountByCanonicalOrder = $this->buildServiceItemCountMap($canonicalOrderIds);

        $amountMismatchSamples = [];
        $statusMismatchSamples = [];
        $fieldMismatchSamples = [];
        $financeMismatchSamples = [];
        $serviceItemMismatchSamples = [];
        $amountMismatchCount = 0;
        $statusMismatchCount = 0;
        $fieldMismatchCount = 0;
        $financeMismatchCount = 0;
        $serviceItemMismatchCount = 0;

        foreach ($pairs as $pair) {
            $legacyAmount = round((float) $pair->service_amount, 2);
            $canonicalAmount = round((float) $pair->canonical_amount, 2);
            if (abs($legacyAmount - $canonicalAmount) > 0.00001) {
                $amountMismatchCount++;
                if (count($amountMismatchSamples) < $sampleLimit) {
                    $amountMismatchSamples[] = [
                        'service_order_id' => (int) $pair->service_order_id,
                        'canonical_order_id' => (int) $pair->canonical_order_id,
                        'service_amount' => $legacyAmount,
                        'canonical_amount' => $canonicalAmount,
                    ];
                }
            }

            $expectedCanonicalStatus = $this->serviceStatusToCanonical((string) $pair->service_status);
            $actualCanonicalStatus = strtolower(trim((string) $pair->canonical_status));
            if ($expectedCanonicalStatus !== $actualCanonicalStatus) {
                $statusMismatchCount++;
                if (count($statusMismatchSamples) < $sampleLimit) {
                    $statusMismatchSamples[] = [
                        'service_order_id' => (int) $pair->service_order_id,
                        'canonical_order_id' => (int) $pair->canonical_order_id,
                        'service_status' => (string) $pair->service_status,
                        'canonical_status' => (string) $pair->canonical_status,
                        'expected_canonical_status' => $expectedCanonicalStatus,
                    ];
                }
            }

            $fieldMismatch = [];
            $this->compareField($fieldMismatch, 'platform_code', $pair->service_platform_code, $pair->canonical_platform_code);
            $this->compareField($fieldMismatch, 'shop_id(account_id)', $pair->service_shop_id, $pair->canonical_shop_id);
            $this->compareField($fieldMismatch, 'platform_order_id', $pair->service_external_order_id, $pair->canonical_external_order_id);
            $this->compareField($fieldMismatch, 'service_name_subject', $pair->service_name, $pair->canonical_subject);
            $this->compareField($fieldMismatch, 'customer_name', $pair->service_customer_name, $pair->canonical_customer_name);
            $this->compareField($fieldMismatch, 'customer_id', $pair->service_customer_id, $pair->canonical_customer_id);
            $this->compareField($fieldMismatch, 'currency', $pair->service_currency, $pair->canonical_currency);
            $this->compareField($fieldMismatch, 'delivery_mode', $pair->service_delivery_mode, $pair->canonical_delivery_mode);
            $this->compareField($fieldMismatch, 'project_id', $pair->service_project_id, $pair->canonical_project_id);
            $this->compareField($fieldMismatch, 'ticket_id', $pair->service_ticket_id, $pair->canonical_ticket_id);

            if (count($fieldMismatch) > 0) {
                $fieldMismatchCount++;
                if (count($fieldMismatchSamples) < $sampleLimit) {
                    $fieldMismatchSamples[] = [
                        'service_order_id' => (int) $pair->service_order_id,
                        'canonical_order_id' => (int) $pair->canonical_order_id,
                        'mismatch_fields' => $fieldMismatch,
                    ];
                }
            }

            $serviceOrderId = (int) $pair->service_order_id;
            $canonicalOrderId = (int) $pair->canonical_order_id;
            $actualFinance = $financeByServiceOrder[$serviceOrderId] ?? $this->emptyFinanceStats();
            $snapshotFinance = $this->extractFinanceSnapshot($pair->canonical_meta_json);
            $financeMismatch = $this->compareFinanceStats($actualFinance, $snapshotFinance);
            if (count($financeMismatch) > 0) {
                $financeMismatchCount++;
                if (count($financeMismatchSamples) < $sampleLimit) {
                    $financeMismatchSamples[] = [
                        'service_order_id' => $serviceOrderId,
                        'canonical_order_id' => $canonicalOrderId,
                        'mismatch_fields' => $financeMismatch,
                        'actual' => $actualFinance,
                        'snapshot' => $snapshotFinance,
                    ];
                }
            }

            $serviceItemCount = (int) ($serviceItemCountByCanonicalOrder[$canonicalOrderId] ?? 0);
            if ($serviceItemCount <= 0) {
                $serviceItemMismatchCount++;
                if (count($serviceItemMismatchSamples) < $sampleLimit) {
                    $serviceItemMismatchSamples[] = [
                        'service_order_id' => $serviceOrderId,
                        'canonical_order_id' => $canonicalOrderId,
                        'service_item_count' => $serviceItemCount,
                        'message' => 'canonical order missing service order_items baseline',
                    ];
                }
            }
        }

        $missingCanonical = $this->serviceBaseQuery($filters, 'so')
            ->leftJoin('orders as o', function ($join): void {
                $join->on('o.legacy_service_order_id', '=', 'so.id')
                    ->where('o.order_type', '=', 'service');
            })
            ->whereNull('o.id');

        $missingCanonicalCount = (clone $missingCanonical)->count();
        $missingCanonicalSamples = (clone $missingCanonical)
            ->select([
                'so.id as service_order_id',
                'so.order_no',
                'so.platform_code',
                'so.shop_id',
                'so.external_order_id',
                'so.status',
                'so.amount',
            ])
            ->orderBy('so.id')
            ->limit($sampleLimit)
            ->get()
            ->map(static fn (object $row): array => [
                'service_order_id' => (int) $row->service_order_id,
                'order_no' => (string) $row->order_no,
                'platform_code' => $row->platform_code,
                'shop_id' => $row->shop_id !== null ? (int) $row->shop_id : null,
                'external_order_id' => $row->external_order_id,
                'status' => (string) $row->status,
                'amount' => round((float) $row->amount, 2),
            ])->all();

        $danglingCanonical = $this->canonicalBaseQuery($filters, 'o')
            ->leftJoin('service_orders as so', 'so.id', '=', 'o.legacy_service_order_id')
            ->where(function ($query): void {
                $query->whereNull('o.legacy_service_order_id')
                    ->orWhereNull('so.id');
            });

        $danglingCanonicalCount = (clone $danglingCanonical)->count();
        $danglingCanonicalSamples = (clone $danglingCanonical)
            ->select([
                'o.id as canonical_order_id',
                'o.order_no',
                'o.legacy_service_order_id',
                'o.platform_code',
                'o.shop_id',
                'o.external_order_id',
                'o.status',
                'o.amount',
            ])
            ->orderBy('o.id')
            ->limit($sampleLimit)
            ->get()
            ->map(static fn (object $row): array => [
                'canonical_order_id' => (int) $row->canonical_order_id,
                'order_no' => (string) $row->order_no,
                'legacy_service_order_id' => $row->legacy_service_order_id !== null ? (int) $row->legacy_service_order_id : null,
                'platform_code' => $row->platform_code,
                'shop_id' => $row->shop_id !== null ? (int) $row->shop_id : null,
                'external_order_id' => $row->external_order_id,
                'status' => (string) $row->status,
                'amount' => round((float) $row->amount, 2),
            ])->all();

        $dependencyChain = $this->buildDependencyChainReport($filters);

        $issueCount = $amountMismatchCount
            + $statusMismatchCount
            + $fieldMismatchCount
            + $financeMismatchCount
            + $serviceItemMismatchCount
            + $missingCanonicalCount
            + $danglingCanonicalCount
            + $dependencyChain['totals']['without_canonical']
            + $dependencyChain['totals']['orphan_legacy_fk']
            + $dependencyChain['project_ticket_link']['project_link_mismatch_count']
            + $dependencyChain['project_ticket_link']['ticket_link_mismatch_count']
            + $dependencyChain['finance_link']['receivable_without_order_count']
            + $dependencyChain['finance_link']['refund_without_order_count']
            + $dependencyChain['finance_link']['reconciliation_without_order_count'];

        return [
            'generated_at' => now()->toDateTimeString(),
            'sample_limit' => $sampleLimit,
            'filters' => [
                'date_from' => $filters['date_from']?->toDateString(),
                'date_to' => $filters['date_to']?->toDateString(),
                'platform_code' => $filters['platform_code'],
                'shop_id' => $filters['shop_id'],
            ],
            'healthy' => $issueCount === 0,
            'counts' => [
                'service_orders_total' => $serviceTotal,
                'canonical_orders_total' => $canonicalTotal,
                'linked_total' => $linkedTotal,
                'missing_canonical_total' => $missingCanonicalCount,
                'dangling_canonical_total' => $danglingCanonicalCount,
            ],
            'diff' => [
                'amount_mismatch' => [
                    'count' => $amountMismatchCount,
                    'samples' => $amountMismatchSamples,
                ],
                'status_mismatch' => [
                    'count' => $statusMismatchCount,
                    'samples' => $statusMismatchSamples,
                ],
                'key_field_mismatch' => [
                    'count' => $fieldMismatchCount,
                    'samples' => $fieldMismatchSamples,
                ],
                'finance_snapshot_mismatch' => [
                    'count' => $financeMismatchCount,
                    'samples' => $financeMismatchSamples,
                ],
                'service_item_mismatch' => [
                    'count' => $serviceItemMismatchCount,
                    'samples' => $serviceItemMismatchSamples,
                ],
                'missing_canonical' => [
                    'count' => $missingCanonicalCount,
                    'samples' => $missingCanonicalSamples,
                ],
                'dangling_canonical' => [
                    'count' => $danglingCanonicalCount,
                    'samples' => $danglingCanonicalSamples,
                ],
            ],
            'dependency_chain' => $dependencyChain,
        ];
    }

    private function resolveFilters(array $options): array
    {
        $dateFromRaw = trim((string) ($options['date_from'] ?? ''));
        $dateToRaw = trim((string) ($options['date_to'] ?? ''));
        $platformCode = trim((string) ($options['platform_code'] ?? ''));
        $shopId = $options['shop_id'] ?? null;
        $shopId = $shopId !== null && $shopId !== '' ? max(1, (int) $shopId) : null;

        $dateFrom = null;
        $dateTo = null;
        if ($dateFromRaw !== '') {
            try {
                $dateFrom = CarbonImmutable::parse($dateFromRaw)->startOfDay();
            } catch (\Throwable) {
                $dateFrom = null;
            }
        }
        if ($dateToRaw !== '') {
            try {
                $dateTo = CarbonImmutable::parse($dateToRaw)->endOfDay();
            } catch (\Throwable) {
                $dateTo = null;
            }
        }
        if ($dateFrom !== null && $dateTo !== null && $dateFrom->gt($dateTo)) {
            $tmp = $dateFrom;
            $dateFrom = $dateTo->startOfDay();
            $dateTo = $tmp->endOfDay();
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'platform_code' => $platformCode !== '' ? $platformCode : null,
            'shop_id' => $shopId,
        ];
    }

    private function serviceBaseQuery(array $filters, string $alias = 'service_orders'): Builder
    {
        $query = DB::table('service_orders as '.$alias);
        return $this->applyServiceFilters($query, $filters, $alias);
    }

    private function canonicalBaseQuery(array $filters, string $alias = 'orders'): Builder
    {
        $query = DB::table('orders as '.$alias)->where($alias.'.order_type', 'service');
        return $this->applyCanonicalFilters($query, $filters, $alias);
    }

    private function linkedBaseQuery(array $filters): Builder
    {
        $query = DB::table('service_orders as so')
            ->join('orders as o', function ($join): void {
                $join->on('o.legacy_service_order_id', '=', 'so.id')
                    ->where('o.order_type', '=', 'service');
            });

        $this->applyServiceFilters($query, $filters, 'so');
        $this->applyCanonicalFilters($query, $filters, 'o');

        return $query;
    }

    private function applyServiceFilters(Builder $query, array $filters, string $alias): Builder
    {
        if ($filters['date_from'] instanceof CarbonImmutable) {
            $query->where($alias.'.created_at', '>=', $filters['date_from']);
        }
        if ($filters['date_to'] instanceof CarbonImmutable) {
            $query->where($alias.'.created_at', '<=', $filters['date_to']);
        }
        if (is_string($filters['platform_code']) && $filters['platform_code'] !== '') {
            $query->where($alias.'.platform_code', $filters['platform_code']);
        }
        if (is_int($filters['shop_id']) && $filters['shop_id'] > 0) {
            $query->where($alias.'.shop_id', $filters['shop_id']);
        }

        return $query;
    }

    private function applyCanonicalFilters(Builder $query, array $filters, string $alias): Builder
    {
        if ($filters['date_from'] instanceof CarbonImmutable) {
            $query->where($alias.'.created_at', '>=', $filters['date_from']);
        }
        if ($filters['date_to'] instanceof CarbonImmutable) {
            $query->where($alias.'.created_at', '<=', $filters['date_to']);
        }
        if (is_string($filters['platform_code']) && $filters['platform_code'] !== '') {
            $query->where($alias.'.platform_code', $filters['platform_code']);
        }
        if (is_int($filters['shop_id']) && $filters['shop_id'] > 0) {
            $query->where($alias.'.shop_id', $filters['shop_id']);
        }

        return $query;
    }

    private function buildFinanceStatsByServiceOrder(array $serviceOrderIds): array
    {
        if (count($serviceOrderIds) === 0) {
            return [];
        }

        $stats = [];
        foreach ($serviceOrderIds as $serviceOrderId) {
            $stats[(int) $serviceOrderId] = $this->emptyFinanceStats();
        }

        $receivableRows = DB::table('receivable_records')
            ->select([
                'service_order_id',
                DB::raw('COUNT(*) AS total_count'),
                DB::raw('MAX(id) AS latest_id'),
            ])
            ->whereIn('service_order_id', $serviceOrderIds)
            ->groupBy('service_order_id')
            ->get();
        foreach ($receivableRows as $row) {
            $serviceOrderId = (int) $row->service_order_id;
            $stats[$serviceOrderId]['receivable_count'] = (int) $row->total_count;
            $stats[$serviceOrderId]['receivable_record_id'] = $row->latest_id !== null ? (int) $row->latest_id : null;
        }

        $paymentRows = DB::table('payment_records')
            ->select([
                'service_order_id',
                DB::raw('COUNT(*) AS total_count'),
            ])
            ->whereIn('service_order_id', $serviceOrderIds)
            ->groupBy('service_order_id')
            ->get();
        foreach ($paymentRows as $row) {
            $stats[(int) $row->service_order_id]['payment_count'] = (int) $row->total_count;
        }

        $refundRows = DB::table('refund_records')
            ->select([
                'service_order_id',
                DB::raw('COUNT(*) AS total_count'),
            ])
            ->whereIn('service_order_id', $serviceOrderIds)
            ->groupBy('service_order_id')
            ->get();
        foreach ($refundRows as $row) {
            $stats[(int) $row->service_order_id]['refund_count'] = (int) $row->total_count;
        }

        $reconciliationRows = DB::table('reconciliation_records')
            ->select([
                'service_order_id',
                DB::raw('COUNT(*) AS total_count'),
            ])
            ->whereIn('service_order_id', $serviceOrderIds)
            ->groupBy('service_order_id')
            ->get();
        foreach ($reconciliationRows as $row) {
            $stats[(int) $row->service_order_id]['reconciliation_count'] = (int) $row->total_count;
        }

        return $stats;
    }

    private function buildServiceItemCountMap(array $canonicalOrderIds): array
    {
        if (count($canonicalOrderIds) === 0) {
            return [];
        }

        return DB::table('order_items')
            ->select([
                'order_id',
                DB::raw('COUNT(*) AS total_count'),
            ])
            ->whereIn('order_id', $canonicalOrderIds)
            ->where('item_type', 'service')
            ->groupBy('order_id')
            ->pluck('total_count', 'order_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
    }

    private function compareField(array &$bucket, string $field, mixed $legacyValue, mixed $canonicalValue): void
    {
        $left = $this->normalizeValue($legacyValue);
        $right = $this->normalizeValue($canonicalValue);
        if ($left === $right) {
            return;
        }

        $bucket[] = [
            'field' => $field,
            'service_value' => $legacyValue,
            'canonical_value' => $canonicalValue,
        ];
    }

    private function normalizeValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function compareFinanceStats(array $actual, array $snapshot): array
    {
        $fields = [
            'receivable_count',
            'payment_count',
            'refund_count',
            'reconciliation_count',
            'receivable_record_id',
        ];

        $mismatches = [];
        foreach ($fields as $field) {
            $actualValue = $actual[$field] ?? null;
            $snapshotValue = $snapshot[$field] ?? null;
            if ((string) $actualValue === (string) $snapshotValue) {
                continue;
            }
            $mismatches[] = [
                'field' => $field,
                'actual' => $actualValue,
                'snapshot' => $snapshotValue,
            ];
        }

        return $mismatches;
    }

    private function extractFinanceSnapshot(mixed $rawMeta): array
    {
        $meta = $this->toArray($rawMeta);
        $finance = [];
        if (is_array($meta['finance_snapshot'] ?? null)) {
            $finance = $meta['finance_snapshot'];
        }

        return [
            'receivable_count' => (int) ($finance['receivable_count'] ?? 0),
            'payment_count' => (int) ($finance['payment_count'] ?? 0),
            'refund_count' => (int) ($finance['refund_count'] ?? 0),
            'reconciliation_count' => (int) ($finance['reconciliation_count'] ?? 0),
            'receivable_record_id' => isset($finance['receivable_record_id']) ? (int) $finance['receivable_record_id'] : null,
        ];
    }

    private function emptyFinanceStats(): array
    {
        return [
            'receivable_count' => 0,
            'payment_count' => 0,
            'refund_count' => 0,
            'reconciliation_count' => 0,
            'receivable_record_id' => null,
        ];
    }

    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function serviceStatusToCanonical(string $status): string
    {
        $value = strtolower(trim($status));
        return match ($value) {
            'pending' => 'pending',
            'confirmed' => 'confirmed',
            'in_delivery' => 'in_delivery',
            'completed' => 'completed',
            'after_sale' => 'after_sale',
            'closed' => 'closed',
            default => 'pending',
        };
    }

    private function buildDependencyChainReport(array $filters): array
    {
        $tables = [
            'projects',
            'tickets',
            'receivable_records',
            'payment_records',
            'refund_records',
            'reconciliation_records',
        ];

        $scope = $this->serviceBaseQuery($filters, 'so')->select('so.id');
        $scopeWithLinks = $this->serviceBaseQuery($filters, 'so')
            ->select(['so.id', 'so.project_id', 'so.ticket_id']);
        $scopeServiceIds = $this->serviceBaseQuery($filters, 'so')
            ->select('so.id as id')
            ->pluck('id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->unique()
            ->values()
            ->all();

        $items = [];
        $totalWithoutCanonical = 0;
        $totalOrphanLegacyFk = 0;

        foreach ($tables as $table) {
            $total = DB::table($table.' as t')
                ->joinSub(clone $scope, 'scope', function ($join): void {
                    $join->on('scope.id', '=', 't.service_order_id');
                })
                ->count();

            $orphanLegacyFk = DB::table($table.' as t')
                ->leftJoin('service_orders as so', 'so.id', '=', 't.service_order_id')
                ->whereNotNull('t.service_order_id')
                ->whereNull('so.id')
                ->count();

            $withoutCanonical = DB::table($table.' as t')
                ->joinSub(clone $scope, 'scope', function ($join): void {
                    $join->on('scope.id', '=', 't.service_order_id');
                })
                ->leftJoin('orders as o', function ($join): void {
                    $join->on('o.legacy_service_order_id', '=', 'scope.id')
                        ->where('o.order_type', '=', 'service');
                })
                ->whereNull('o.id')
                ->count();

            $totalWithoutCanonical += $withoutCanonical;
            $totalOrphanLegacyFk += $orphanLegacyFk;
            $items[] = [
                'table' => $table,
                'total' => $total,
                'orphan_legacy_fk' => $orphanLegacyFk,
                'without_canonical' => $withoutCanonical,
            ];
        }

        $projectLinkMismatchCount = DB::table('service_orders as so')
            ->joinSub(clone $scopeWithLinks, 'scope', function ($join): void {
                $join->on('scope.id', '=', 'so.id');
            })
            ->leftJoin('projects as p', 'p.id', '=', 'so.project_id')
            ->whereNotNull('so.project_id')
            ->where(function ($query): void {
                $query->whereNull('p.id')
                    ->orWhereColumn('p.service_order_id', '<>', 'so.id');
            })
            ->count();

        $ticketLinkMismatchCount = DB::table('service_orders as so')
            ->joinSub(clone $scopeWithLinks, 'scope', function ($join): void {
                $join->on('scope.id', '=', 'so.id');
            })
            ->leftJoin('tickets as t', 't.id', '=', 'so.ticket_id')
            ->whereNotNull('so.ticket_id')
            ->where(function ($query): void {
                $query->whereNull('t.id')
                    ->orWhereColumn('t.service_order_id', '<>', 'so.id');
            })
            ->count();

        $receivableWithoutOrderCount = 0;
        $refundWithoutOrderCount = 0;
        $reconciliationWithoutOrderCount = 0;
        if (count($scopeServiceIds) > 0) {
            $receivableWithoutOrderCount = DB::table('receivable_records as rr')
                ->whereIn('rr.service_order_id', $scopeServiceIds)
                ->leftJoin('orders as o', function ($join): void {
                    $join->on('o.legacy_service_order_id', '=', 'rr.service_order_id')
                        ->where('o.order_type', '=', 'service');
                })
                ->whereNull('o.id')
                ->count();
            $refundWithoutOrderCount = DB::table('refund_records as rf')
                ->whereIn('rf.service_order_id', $scopeServiceIds)
                ->leftJoin('orders as o', function ($join): void {
                    $join->on('o.legacy_service_order_id', '=', 'rf.service_order_id')
                        ->where('o.order_type', '=', 'service');
                })
                ->whereNull('o.id')
                ->count();
            $reconciliationWithoutOrderCount = DB::table('reconciliation_records as rc')
                ->whereIn('rc.service_order_id', $scopeServiceIds)
                ->leftJoin('orders as o', function ($join): void {
                    $join->on('o.legacy_service_order_id', '=', 'rc.service_order_id')
                        ->where('o.order_type', '=', 'service');
                })
                ->whereNull('o.id')
                ->count();
        }

        return [
            'tables' => $items,
            'totals' => [
                'orphan_legacy_fk' => $totalOrphanLegacyFk,
                'without_canonical' => $totalWithoutCanonical,
            ],
            'project_ticket_link' => [
                'project_link_mismatch_count' => $projectLinkMismatchCount,
                'ticket_link_mismatch_count' => $ticketLinkMismatchCount,
            ],
            'finance_link' => [
                'receivable_without_order_count' => $receivableWithoutOrderCount,
                'refund_without_order_count' => $refundWithoutOrderCount,
                'reconciliation_without_order_count' => $reconciliationWithoutOrderCount,
            ],
        ];
    }
}
