<?php

namespace App\Services\ChannelHub;

use App\Models\RefundRecord;
use App\Models\ServiceOrder;
use App\Services\ChannelHub\Mapping\RawPayloadParser;
use App\Services\ChannelHub\Mapping\RawStatusMapper;
use App\Services\ChannelHub\Mapping\RefundDomainService;
use App\Services\ChannelHub\Mapping\ServiceOrderDomainService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RawChannelMappingService
{
    public function __construct(
        private readonly RawPayloadParser $payloadParser,
        private readonly RawStatusMapper $statusMapper,
        private readonly ServiceOrderDomainService $serviceOrderDomain,
        private readonly RefundDomainService $refundDomain
    ) {
    }

    public function run(array $options = []): array
    {
        $limit = max(1, min(1000, (int) ($options['limit'] ?? 100)));
        $startedAt = now();

        $orders = $this->mapPendingRawOrders($limit);
        $refunds = $this->mapPendingRawRefunds($limit);

        return [
            'started_at' => $startedAt->toDateTimeString(),
            'finished_at' => now()->toDateTimeString(),
            'limit' => $limit,
            'orders' => $orders,
            'refunds' => $refunds,
            'summary' => $this->summary(),
        ];
    }

    public function summary(): array
    {
        return [
            'raw_orders' => $this->statusCount('raw_orders'),
            'raw_refunds' => $this->statusCount('raw_refunds'),
            'raw_listings' => $this->statusCount('raw_listings'),
            'raw_services' => $this->statusCount('raw_services'),
        ];
    }

    private function mapPendingRawOrders(int $limit): array
    {
        $rows = DB::table('raw_orders')
            ->where('mapped_status', 'PENDING')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $stats = [
            'picked' => $rows->count(),
            'created' => 0,
            'updated' => 0,
            'mapped' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($rows as $row) {
            try {
                $result = DB::transaction(fn (): array => $this->mapSingleRawOrder($row));
                $stats['created'] += $result['created'];
                $stats['updated'] += $result['updated'];
                $stats['mapped'] += $result['mapped'];
                $stats['skipped'] += $result['skipped'];
            } catch (\Throwable $e) {
                $stats['failed']++;
                DB::table('raw_orders')
                    ->where('id', $row->id)
                    ->update([
                        'mapped_status' => 'FAILED',
                        'processed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        }

        return $stats;
    }

    private function mapPendingRawRefunds(int $limit): array
    {
        $rows = DB::table('raw_refunds')
            ->where('mapped_status', 'PENDING')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $stats = [
            'picked' => $rows->count(),
            'created' => 0,
            'updated' => 0,
            'mapped' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($rows as $row) {
            try {
                $result = DB::transaction(fn (): array => $this->mapSingleRawRefund($row));
                $stats['created'] += $result['created'];
                $stats['updated'] += $result['updated'];
                $stats['mapped'] += $result['mapped'];
                $stats['skipped'] += $result['skipped'];
            } catch (\Throwable $e) {
                $stats['failed']++;
                DB::table('raw_refunds')
                    ->where('id', $row->id)
                    ->update([
                        'mapped_status' => 'FAILED',
                        'processed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        }

        return $stats;
    }

    /**
     * @param object{id:int,platform_code:string,shop_id:int|null,payload_json:mixed,external_order_id:string|null} $row
     * @return array{created:int,updated:int,mapped:int,skipped:int}
     */
    private function mapSingleRawOrder(object $row): array
    {
        $payload = $this->payloadParser->decode($row->payload_json);
        $records = $this->payloadParser->extractRecords($payload);

        if (count($records) === 0) {
            $this->finishRaw('raw_orders', (int) $row->id, 'SKIPPED');
            return ['created' => 0, 'updated' => 0, 'mapped' => 0, 'skipped' => 1];
        }

        $created = 0;
        $updated = 0;
        $mapped = 0;
        $skipped = 0;

        foreach ($records as $record) {
            $orderType = strtolower((string) ($record['order_type'] ?? 'service'));
            if ($orderType !== 'service') {
                $skipped++;
                continue;
            }

            $externalOrderId = trim((string) ($record['external_order_id'] ?? $row->external_order_id ?? ''));
            if ($externalOrderId === '') {
                $skipped++;
                continue;
            }

            $serviceName = trim((string) ($record['subject'] ?? $record['service_name'] ?? '渠道服务订单'));
            $customerName = trim((string) ($record['buyer_id'] ?? $record['customer_name'] ?? ''));
            $currency = strtoupper((string) ($record['currency'] ?? 'CNY'));
            $amount = round(max(0.0, (float) ($record['amount'] ?? 0)), 2);
            $targetStatus = $this->statusMapper->normalizeOrderStatus((string) ($record['status'] ?? 'pending'));

            $order = ServiceOrder::query()
                ->where('platform_code', $row->platform_code)
                ->where('shop_id', $row->shop_id)
                ->where('external_order_id', $externalOrderId)
                ->first();

            $isNew = false;
            if (! $order) {
                $isNew = true;
                $order = new ServiceOrder();
                $order->order_no = sprintf('SO%s%s', now()->format('YmdHis'), strtoupper(Str::random(4)));
                $order->platform_code = $row->platform_code;
                $order->shop_id = $row->shop_id;
                $order->external_order_id = $externalOrderId;
                $order->delivery_mode = 'auto';
                $order->status = 'pending';
            }

            $order->service_name = $serviceName !== '' ? $serviceName : '渠道服务订单';
            $order->customer_name = $customerName !== '' ? $customerName : null;
            $order->currency = $currency;
            $order->amount = $amount;

            $this->serviceOrderDomain->applyMappedStatus($order, $targetStatus, $this->statusMapper);
            $order->save();

            if ($isNew) {
                $created++;
            } else {
                $updated++;
            }
            $mapped++;

            if (
                $this->statusMapper->statusPriority((string) $order->status)
                >= $this->statusMapper->statusPriority('confirmed')
            ) {
                $this->serviceOrderDomain->ensureReceivable($order);
                $this->serviceOrderDomain->ensureDeliveryObject($order);
            }
        }

        $finalStatus = $mapped > 0 ? 'MAPPED' : ($skipped > 0 ? 'SKIPPED' : 'FAILED');
        $this->finishRaw('raw_orders', (int) $row->id, $finalStatus);

        return [
            'created' => $created,
            'updated' => $updated,
            'mapped' => $mapped,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param object{id:int,platform_code:string,shop_id:int|null,payload_json:mixed,external_refund_id:string|null} $row
     * @return array{created:int,updated:int,mapped:int,skipped:int}
     */
    private function mapSingleRawRefund(object $row): array
    {
        $payload = $this->payloadParser->decode($row->payload_json);
        $records = $this->payloadParser->extractRecords($payload);

        if (count($records) === 0) {
            $this->finishRaw('raw_refunds', (int) $row->id, 'SKIPPED');
            return ['created' => 0, 'updated' => 0, 'mapped' => 0, 'skipped' => 1];
        }

        $created = 0;
        $updated = 0;
        $mapped = 0;
        $skipped = 0;

        foreach ($records as $record) {
            $externalOrderId = trim((string) ($record['external_order_id'] ?? ''));
            if ($externalOrderId === '') {
                $skipped++;
                continue;
            }

            $order = ServiceOrder::query()
                ->where('platform_code', $row->platform_code)
                ->where('shop_id', $row->shop_id)
                ->where('external_order_id', $externalOrderId)
                ->first();
            if (! $order) {
                $skipped++;
                continue;
            }

            $externalRefundId = trim((string) ($record['external_refund_id'] ?? $row->external_refund_id ?? ''));
            $refundAmount = round(max(0.0, (float) ($record['amount'] ?? 0)), 2);
            $currency = strtoupper((string) ($record['currency'] ?? $order->currency ?? 'CNY'));
            $reason = trim((string) ($record['reason'] ?? ''));
            $targetStatus = $this->statusMapper->normalizeRefundStatus((string) ($record['status'] ?? 'PENDING'));
            $refundedAt = $this->parseDate($record['refunded_at'] ?? null) ?? now();

            $query = RefundRecord::query()
                ->where('service_order_id', $order->id)
                ->where('platform_code', $row->platform_code);
            if ($externalRefundId !== '') {
                $query->where('external_refund_id', $externalRefundId);
            } else {
                $query->whereNull('external_refund_id')
                    ->where('amount', $refundAmount)
                    ->where('reason', $reason === '' ? null : $reason);
            }
            $refund = $query->first();

            $isNew = false;
            $oldStatus = null;
            $oldAmount = 0.0;
            if (! $refund) {
                $isNew = true;
                $refund = new RefundRecord();
                $refund->refund_no = sprintf('RFD%s%s', now()->format('YmdHis'), strtoupper(Str::random(4)));
                $refund->service_order_id = $order->id;
                $refund->payment_record_id = null;
            } else {
                $oldStatus = strtoupper((string) $refund->status);
                $oldAmount = (float) $refund->amount;
            }

            $refund->platform_code = $row->platform_code;
            $refund->external_refund_id = $externalRefundId !== '' ? $externalRefundId : null;
            $refund->amount = $refundAmount;
            $refund->currency = $currency;
            $refund->status = $targetStatus;
            $refund->reason = $reason !== '' ? $reason : null;
            $refund->refunded_at = $refundedAt;
            $refund->save();

            if ($isNew) {
                $created++;
            } else {
                $updated++;
            }
            $mapped++;

            $this->refundDomain->applyReceivableDeltaByRefundTransition(
                $order,
                $oldStatus,
                $targetStatus,
                $oldAmount,
                $refundAmount,
                $this->statusMapper,
                $this->serviceOrderDomain
            );
            $this->refundDomain->upsertReconciliationByRefund($order, $refund, $this->statusMapper);

            if (
                $this->statusMapper->isRefundEffective($targetStatus)
                && $this->statusMapper->statusPriority((string) $order->status) < $this->statusMapper->statusPriority('after_sale')
            ) {
                $order->status = 'after_sale';
                $order->save();
            }
        }

        $finalStatus = $mapped > 0 ? 'MAPPED' : ($skipped > 0 ? 'SKIPPED' : 'FAILED');
        $this->finishRaw('raw_refunds', (int) $row->id, $finalStatus);

        return [
            'created' => $created,
            'updated' => $updated,
            'mapped' => $mapped,
            'skipped' => $skipped,
        ];
    }

    private function finishRaw(string $table, int $id, string $status): void
    {
        DB::table($table)
            ->where('id', $id)
            ->update([
                'mapped_status' => $status,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function statusCount(string $table): array
    {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            return [];
        }

        $rows = DB::table($table)
            ->select('mapped_status', DB::raw('COUNT(*) AS total'))
            ->groupBy('mapped_status')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->mapped_status] = (int) $row->total;
        }

        return $result;
    }
}
