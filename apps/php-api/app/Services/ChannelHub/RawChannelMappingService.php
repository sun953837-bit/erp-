<?php

namespace App\Services\ChannelHub;

use App\Models\GoodsOrderFulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PlatformProductMapping;
use App\Models\ProductSku;
use App\Models\RefundRecord;
use App\Models\ServiceOrder;
use App\Services\ChannelHub\Mapping\RawPayloadParser;
use App\Services\ChannelHub\Mapping\RawStatusMapper;
use App\Services\ChannelHub\Mapping\RefundDomainService;
use App\Services\ChannelHub\Mapping\ServiceOrderDomainService;
use App\Services\Order\ServiceOrderDualWriteService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RawChannelMappingService
{
    public function __construct(
        private readonly RawPayloadParser $payloadParser,
        private readonly RawStatusMapper $statusMapper,
        private readonly ServiceOrderDomainService $serviceOrderDomain,
        private readonly RefundDomainService $refundDomain,
        private readonly ServiceOrderDualWriteService $dualWrite
    ) {
    }

    public function run(array $options = []): array
    {
        $limit = max(1, min(1000, (int) ($options['limit'] ?? 100)));
        $startedAt = now();

        $orders = $this->mapPendingRawOrders($limit);
        $refunds = $this->mapPendingRawRefunds($limit);
        $listings = $this->mapPendingRawListings($limit);

        return [
            'started_at' => $startedAt->toDateTimeString(),
            'finished_at' => now()->toDateTimeString(),
            'limit' => $limit,
            'orders' => $orders,
            'refunds' => $refunds,
            'listings' => $listings,
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

    private function mapPendingRawListings(int $limit): array
    {
        $rows = DB::table('raw_listings')
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
                $result = DB::transaction(fn (): array => $this->mapSingleRawListing($row));
                $stats['created'] += $result['created'];
                $stats['updated'] += $result['updated'];
                $stats['mapped'] += $result['mapped'];
                $stats['skipped'] += $result['skipped'];
            } catch (\Throwable) {
                $stats['failed']++;
                DB::table('raw_listings')
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
            if ($orderType === 'goods') {
                $result = $this->mapGoodsOrderRecord($row, $record);
                $created += $result['created'];
                $updated += $result['updated'];
                $mapped += $result['mapped'];
                $skipped += $result['skipped'];
                continue;
            }

            if ($orderType !== 'service') {
                $skipped++;
                continue;
            }

            $result = $this->mapServiceOrderRecord($row, $record);
            $created += $result['created'];
            $updated += $result['updated'];
            $mapped += $result['mapped'];
            $skipped += $result['skipped'];
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
     * @param object{platform_code:string,shop_id:int|null,external_order_id:string|null} $row
     * @param array<string,mixed> $record
     * @return array{created:int,updated:int,mapped:int,skipped:int}
     */
    private function mapServiceOrderRecord(object $row, array $record): array
    {
        $externalOrderId = trim((string) ($record['external_order_id'] ?? $row->external_order_id ?? ''));
        if ($externalOrderId === '') {
            return ['created' => 0, 'updated' => 0, 'mapped' => 0, 'skipped' => 1];
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

        if (
            $this->statusMapper->statusPriority((string) $order->status)
            >= $this->statusMapper->statusPriority('confirmed')
        ) {
            $this->serviceOrderDomain->ensureReceivable($order);
            $this->serviceOrderDomain->ensureDeliveryObject($order);
        }

        if ($this->isDualWriteEnabled()) {
            $this->dualWrite->syncCanonicalFromServiceOrder($order);
        }

        return [
            'created' => $isNew ? 1 : 0,
            'updated' => $isNew ? 0 : 1,
            'mapped' => 1,
            'skipped' => 0,
        ];
    }

    /**
     * @param object{platform_code:string,shop_id:int|null,external_order_id:string|null} $row
     * @param array<string,mixed> $record
     * @return array{created:int,updated:int,mapped:int,skipped:int}
     */
    private function mapGoodsOrderRecord(object $row, array $record): array
    {
        $externalOrderId = trim((string) ($record['external_order_id'] ?? $row->external_order_id ?? ''));
        if ($externalOrderId === '') {
            return ['created' => 0, 'updated' => 0, 'mapped' => 0, 'skipped' => 1];
        }

        $subject = trim((string) ($record['subject'] ?? $record['title'] ?? $record['service_name'] ?? '渠道商品订单'));
        if ($subject === '') {
            $subject = '渠道商品订单';
        }
        $customerName = trim((string) ($record['buyer_id'] ?? $record['customer_name'] ?? ''));
        $currency = strtoupper((string) ($record['currency'] ?? 'CNY'));
        $amount = round(max(0.0, (float) ($record['amount'] ?? 0)), 2);
        $targetStatus = $this->normalizeGoodsStatus((string) ($record['status'] ?? 'pending'));

        $order = Order::query()
            ->where('order_type', 'goods')
            ->where('platform_code', $row->platform_code)
            ->where('shop_id', $row->shop_id)
            ->where('external_order_id', $externalOrderId)
            ->first();

        $isNew = false;
        if (! $order) {
            $isNew = true;
            $order = new Order();
            $order->order_no = sprintf('ORD%s%s', now()->format('YmdHis'), strtoupper(Str::random(4)));
            $order->order_type = 'goods';
            $order->delivery_mode = 'shipment';
            $order->status = 'pending';
        }

        $order->platform_code = $row->platform_code;
        $order->shop_id = $row->shop_id;
        $order->external_order_id = $externalOrderId;
        $order->subject = $subject;
        $order->customer_name = $customerName !== '' ? $customerName : null;
        $order->currency = $currency;
        $order->amount = $amount;
        $order->status = $targetStatus;
        $order->meta_json = [
            'source' => 'raw_orders',
            'raw_order_type' => 'goods',
            'mapped_at' => now()->toDateTimeString(),
        ];
        if ($targetStatus === 'confirmed' && $order->confirmed_at === null) {
            $order->confirmed_at = now();
        }
        if ($targetStatus === 'completed' && $order->completed_at === null) {
            $order->completed_at = now();
        }
        $order->save();

        $existingItems = OrderItem::query()->where('order_id', $order->id)->exists();
        if (! $existingItems) {
            foreach ($this->extractGoodsOrderItems($record, $order, $currency, $amount) as $item) {
                OrderItem::query()->create($item);
            }
        }

        if (in_array($targetStatus, ['confirmed', 'shipped', 'completed'], true)) {
            $this->ensureGoodsFulfillment($order);
        }

        return [
            'created' => $isNew ? 1 : 0,
            'updated' => $isNew ? 0 : 1,
            'mapped' => 1,
            'skipped' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $record
     * @return array<int,array<string,mixed>>
     */
    private function extractGoodsOrderItems(array $record, Order $order, string $currency, float $amount): array
    {
        $itemsPayload = $record['items'] ?? null;
        $items = [];

        if (is_array($itemsPayload)) {
            foreach ($itemsPayload as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                $unitPrice = round(max(0.0, (float) ($item['unit_price'] ?? $item['price'] ?? 0)), 2);
                $title = trim((string) ($item['title'] ?? $item['name'] ?? $order->subject));
                $skuCode = trim((string) ($item['sku_code'] ?? $item['external_sku_id'] ?? ''));

                $items[] = [
                    'order_id' => $order->id,
                    'item_no' => sprintf('ITM%s%s', now()->format('YmdHis'), strtoupper(Str::random(4))),
                    'item_type' => 'goods',
                    'sku_code' => $skuCode !== '' ? $skuCode : null,
                    'title' => $title !== '' ? $title : $order->subject,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => round($unitPrice * $quantity, 2),
                    'currency' => strtoupper((string) ($item['currency'] ?? $currency)),
                    'meta_json' => [
                        'source' => 'raw_orders',
                    ],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if (count($items) > 0) {
            return $items;
        }

        return [[
            'order_id' => $order->id,
            'item_no' => sprintf('ITM%s%s', now()->format('YmdHis'), strtoupper(Str::random(4))),
            'item_type' => 'goods',
            'sku_code' => null,
            'title' => $order->subject,
            'quantity' => 1,
            'unit_price' => $amount,
            'total_price' => $amount,
            'currency' => $currency,
            'meta_json' => [
                'source' => 'raw_orders',
                'fallback_item' => true,
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]];
    }

    private function ensureGoodsFulfillment(Order $order): void
    {
        $exists = GoodsOrderFulfillment::query()
            ->where('order_id', $order->id)
            ->exists();
        if ($exists) {
            return;
        }

        GoodsOrderFulfillment::query()->create([
            'order_id' => $order->id,
            'fulfillment_no' => sprintf('FUL%s%s', now()->format('YmdHis'), strtoupper(Str::random(4))),
            'logistics_status' => 'pending',
            'carrier' => null,
            'tracking_no' => null,
            'shipped_at' => null,
            'delivered_at' => null,
            'meta_json' => [
                'source' => 'raw_orders',
                'baseline_reserved' => true,
            ],
        ]);
    }

    /**
     * @param object{id:int,platform_code:string,shop_id:int|null,site_code:string|null,payload_json:mixed,external_listing_id:string|null} $row
     * @return array{created:int,updated:int,mapped:int,skipped:int}
     */
    private function mapSingleRawListing(object $row): array
    {
        $payload = $this->payloadParser->decode($row->payload_json);
        $records = $this->payloadParser->extractRecords($payload);
        if (count($records) === 0) {
            $this->finishRaw('raw_listings', (int) $row->id, 'SKIPPED');
            return ['created' => 0, 'updated' => 0, 'mapped' => 0, 'skipped' => 1];
        }

        $created = 0;
        $updated = 0;
        $mapped = 0;
        $skipped = 0;

        foreach ($records as $record) {
            $listingType = strtolower((string) ($record['listing_type'] ?? $record['order_type'] ?? $record['biz_type'] ?? ''));
            if ($listingType !== '' && $listingType !== 'goods') {
                $skipped++;
                continue;
            }

            if ($row->shop_id === null) {
                $skipped++;
                continue;
            }

            $externalListingId = trim((string) ($record['external_listing_id'] ?? $row->external_listing_id ?? ''));
            if ($externalListingId === '') {
                $skipped++;
                continue;
            }

            $skuCode = trim((string) ($record['sku_code'] ?? $record['external_sku_id'] ?? $record['sku'] ?? ''));
            if ($skuCode === '') {
                $skipped++;
                continue;
            }

            $sku = ProductSku::query()
                ->where('sku_code', $skuCode)
                ->first();
            if (! $sku) {
                $skipped++;
                continue;
            }

            $mapping = PlatformProductMapping::query()
                ->where('shop_id', (int) $row->shop_id)
                ->where('platform_code', $row->platform_code)
                ->where('external_listing_id', $externalListingId)
                ->first();

            $siteCode = trim((string) ($row->site_code ?? ''));
            if ($siteCode === '') {
                $siteCode = 'UNKNOWN';
            }
            $externalStatus = strtoupper((string) ($record['status'] ?? $record['listing_status'] ?? 'ONLINE'));

            if (! $mapping) {
                $mapping = PlatformProductMapping::query()
                    ->where('shop_id', (int) $row->shop_id)
                    ->where('platform_code', $row->platform_code)
                    ->where('site_code', $siteCode)
                    ->where('sku_id', $sku->id)
                    ->first();
            }

            if (! $mapping) {
                PlatformProductMapping::query()->create([
                    'shop_id' => (int) $row->shop_id,
                    'spu_id' => $sku->spu_id,
                    'sku_id' => $sku->id,
                    'platform_code' => $row->platform_code,
                    'site_code' => $siteCode,
                    'external_listing_id' => $externalListingId,
                    'external_sku_id' => trim((string) ($record['external_sku_id'] ?? '')) ?: null,
                    'external_status' => $externalStatus,
                    'raw_payload' => [
                        'source' => 'raw_listings',
                        'record' => $record,
                    ],
                    'last_synced_at' => now(),
                ]);
                $created++;
                $mapped++;
                continue;
            }

            $mapping->spu_id = $mapping->spu_id ?: $sku->spu_id;
            $mapping->sku_id = $mapping->sku_id ?: $sku->id;
            $mapping->site_code = $mapping->site_code ?: $siteCode;
            $mapping->external_listing_id = $externalListingId;
            $mapping->external_sku_id = trim((string) ($record['external_sku_id'] ?? $mapping->external_sku_id ?? '')) ?: null;
            $mapping->external_status = $externalStatus;
            $mapping->raw_payload = [
                'source' => 'raw_listings',
                'record' => $record,
            ];
            $mapping->last_synced_at = now();
            $mapping->save();

            $updated++;
            $mapped++;
        }

        $finalStatus = $mapped > 0 ? 'MAPPED' : ($skipped > 0 ? 'SKIPPED' : 'FAILED');
        $this->finishRaw('raw_listings', (int) $row->id, $finalStatus);

        return [
            'created' => $created,
            'updated' => $updated,
            'mapped' => $mapped,
            'skipped' => $skipped,
        ];
    }

    private function normalizeGoodsStatus(string $status): string
    {
        $value = strtolower(trim($status));
        return match ($value) {
            'confirmed', 'paid', 'accepted' => 'confirmed',
            'shipped', 'in_delivery', 'delivering' => 'shipped',
            'completed', 'done', 'received' => 'completed',
            'after_sale', 'refunding', 'refunded' => 'after_sale',
            'closed' => 'closed',
            'cancelled', 'canceled' => 'cancelled',
            default => 'pending',
        };
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

            if ($this->isDualWriteEnabled()) {
                $this->dualWrite->syncCanonicalFromServiceOrder($order);
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

    private function isDualWriteEnabled(): bool
    {
        return filter_var((string) env('SERVICE_ORDER_DUAL_WRITE_ENABLED', 'true'), FILTER_VALIDATE_BOOL);
    }
}
