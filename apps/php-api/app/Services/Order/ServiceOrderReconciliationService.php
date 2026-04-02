<?php

namespace App\Services\Order;

use Illuminate\Support\Facades\DB;

class ServiceOrderReconciliationService
{
    public function reconcile(array $options = []): array
    {
        $sampleLimit = max(1, min(500, (int) ($options['sample_limit'] ?? 50)));

        $serviceTotal = DB::table('service_orders')->count();
        $canonicalTotal = DB::table('orders')->where('order_type', 'service')->count();
        $linkedTotal = DB::table('service_orders as so')
            ->join('orders as o', function ($join): void {
                $join->on('o.legacy_service_order_id', '=', 'so.id')
                    ->where('o.order_type', '=', 'service');
            })
            ->count();

        $pairs = DB::table('service_orders as so')
            ->join('orders as o', function ($join): void {
                $join->on('o.legacy_service_order_id', '=', 'so.id')
                    ->where('o.order_type', '=', 'service');
            })
            ->select([
                'so.id as service_order_id',
                'so.order_no as service_order_no',
                'so.platform_code as service_platform_code',
                'so.shop_id as service_shop_id',
                'so.external_order_id as service_external_order_id',
                'so.service_name as service_name',
                'so.customer_name as service_customer_name',
                'so.currency as service_currency',
                'so.amount as service_amount',
                'so.status as service_status',
                'so.delivery_mode as service_delivery_mode',
                'o.id as canonical_order_id',
                'o.order_no as canonical_order_no',
                'o.platform_code as canonical_platform_code',
                'o.shop_id as canonical_shop_id',
                'o.external_order_id as canonical_external_order_id',
                'o.subject as canonical_subject',
                'o.customer_name as canonical_customer_name',
                'o.currency as canonical_currency',
                'o.amount as canonical_amount',
                'o.status as canonical_status',
                'o.delivery_mode as canonical_delivery_mode',
            ])
            ->orderBy('so.id')
            ->get();

        $amountMismatchSamples = [];
        $statusMismatchSamples = [];
        $fieldMismatchSamples = [];
        $amountMismatchCount = 0;
        $statusMismatchCount = 0;
        $fieldMismatchCount = 0;

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
            $this->compareField($fieldMismatch, 'shop_id', $pair->service_shop_id, $pair->canonical_shop_id);
            $this->compareField($fieldMismatch, 'external_order_id', $pair->service_external_order_id, $pair->canonical_external_order_id);
            $this->compareField($fieldMismatch, 'service_name_subject', $pair->service_name, $pair->canonical_subject);
            $this->compareField($fieldMismatch, 'customer_name', $pair->service_customer_name, $pair->canonical_customer_name);
            $this->compareField($fieldMismatch, 'currency', $pair->service_currency, $pair->canonical_currency);
            $this->compareField($fieldMismatch, 'delivery_mode', $pair->service_delivery_mode, $pair->canonical_delivery_mode);

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
        }

        $missingCanonical = DB::table('service_orders as so')
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

        $danglingCanonical = DB::table('orders as o')
            ->leftJoin('service_orders as so', 'so.id', '=', 'o.legacy_service_order_id')
            ->where('o.order_type', 'service')
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

        $dependencyChain = $this->buildDependencyChainReport();

        $issueCount = $amountMismatchCount
            + $statusMismatchCount
            + $fieldMismatchCount
            + $missingCanonicalCount
            + $danglingCanonicalCount
            + $dependencyChain['totals']['without_canonical']
            + $dependencyChain['totals']['orphan_legacy_fk']
            + $dependencyChain['project_ticket_link']['project_link_mismatch_count']
            + $dependencyChain['project_ticket_link']['ticket_link_mismatch_count'];

        return [
            'generated_at' => now()->toDateTimeString(),
            'sample_limit' => $sampleLimit,
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

    private function buildDependencyChainReport(): array
    {
        $tables = [
            'projects',
            'tickets',
            'receivable_records',
            'payment_records',
            'refund_records',
            'reconciliation_records',
        ];

        $items = [];
        $totalWithoutCanonical = 0;
        $totalOrphanLegacyFk = 0;

        foreach ($tables as $table) {
            $total = DB::table($table)->count();
            $orphanLegacyFk = DB::table($table.' as t')
                ->leftJoin('service_orders as so', 'so.id', '=', 't.service_order_id')
                ->whereNotNull('t.service_order_id')
                ->whereNull('so.id')
                ->count();
            $withoutCanonical = DB::table($table.' as t')
                ->join('service_orders as so', 'so.id', '=', 't.service_order_id')
                ->leftJoin('orders as o', function ($join): void {
                    $join->on('o.legacy_service_order_id', '=', 'so.id')
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
            ->leftJoin('projects as p', 'p.id', '=', 'so.project_id')
            ->whereNotNull('so.project_id')
            ->where(function ($query): void {
                $query->whereNull('p.id')
                    ->orWhereColumn('p.service_order_id', '<>', 'so.id');
            })
            ->count();
        $ticketLinkMismatchCount = DB::table('service_orders as so')
            ->leftJoin('tickets as t', 't.id', '=', 'so.ticket_id')
            ->whereNotNull('so.ticket_id')
            ->where(function ($query): void {
                $query->whereNull('t.id')
                    ->orWhereColumn('t.service_order_id', '<>', 'so.id');
            })
            ->count();

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
        ];
    }
}
