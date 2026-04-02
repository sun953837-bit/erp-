<?php

namespace App\Services\ChannelHub;

use App\Models\Project;
use App\Models\ReceivableRecord;
use App\Models\ReconciliationRecord;
use App\Models\RefundRecord;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RawChannelMappingService
{
    /**
     * @var array<string,int>
     */
    private array $orderStatusPriority = [
        'pending' => 10,
        'confirmed' => 20,
        'in_delivery' => 30,
        'completed' => 40,
        'after_sale' => 50,
        'closed' => 60,
    ];

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
        $payload = $this->decodePayload($row->payload_json);
        $records = $this->extractRecords($payload);

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
            $targetStatus = $this->normalizeOrderStatus((string) ($record['status'] ?? 'pending'));

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

            $this->applyOrderStatus($order, $targetStatus);
            $order->save();

            if ($isNew) {
                $created++;
            } else {
                $updated++;
            }
            $mapped++;

            if ($this->statusPriority((string) $order->status) >= $this->statusPriority('confirmed')) {
                $this->ensureReceivable($order);
                $this->ensureDeliveryObject($order);
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
        $payload = $this->decodePayload($row->payload_json);
        $records = $this->extractRecords($payload);

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
            $targetStatus = $this->normalizeRefundStatus((string) ($record['status'] ?? 'PENDING'));
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

            $this->applyReceivableDeltaByRefundTransition($order, $oldStatus, $targetStatus, $oldAmount, $refundAmount);
            $this->upsertReconciliationByRefund($order, $refund);

            if ($this->isRefundEffective($targetStatus) && $this->statusPriority((string) $order->status) < $this->statusPriority('after_sale')) {
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

    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (is_string($payload) && trim($payload) !== '') {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractRecords(array $payload): array
    {
        $records = Arr::get($payload, 'raw_payload.records');
        if (! is_array($records)) {
            $records = Arr::get($payload, 'response.raw_payload.records');
        }
        if (! is_array($records)) {
            $records = Arr::get($payload, 'records');
        }
        if (! is_array($records)) {
            return [];
        }

        return array_values(array_filter($records, static fn (mixed $item): bool => is_array($item)));
    }

    private function normalizeOrderStatus(string $status): string
    {
        $value = strtolower(trim($status));
        return match ($value) {
            'confirmed', 'paid', 'active' => 'confirmed',
            'in_delivery', 'delivering', 'processing' => 'in_delivery',
            'completed', 'done', 'finished', 'success' => 'completed',
            'after_sale', 'refunding' => 'after_sale',
            'closed', 'cancelled', 'canceled' => 'closed',
            default => 'pending',
        };
    }

    private function normalizeRefundStatus(string $status): string
    {
        $value = strtolower(trim($status));
        return match ($value) {
            'approved', 'pass' => 'APPROVED',
            'paid', 'finished', 'success', 'completed' => 'PAID',
            'rejected', 'reject', 'failed' => 'REJECTED',
            default => 'PENDING',
        };
    }

    private function statusPriority(string $status): int
    {
        $key = strtolower($status);
        return $this->orderStatusPriority[$key] ?? 0;
    }

    private function applyOrderStatus(ServiceOrder $order, string $targetStatus): void
    {
        $currentPriority = $this->statusPriority((string) $order->status);
        $targetPriority = $this->statusPriority($targetStatus);

        if ($targetPriority >= $currentPriority) {
            $order->status = $targetStatus;
        }

        if ($this->statusPriority((string) $order->status) >= $this->statusPriority('confirmed') && $order->confirmed_at === null) {
            $order->confirmed_at = now();
        }
        if ($this->statusPriority((string) $order->status) >= $this->statusPriority('completed') && $order->completed_at === null) {
            $order->completed_at = now();
        }
    }

    private function ensureReceivable(ServiceOrder $order): void
    {
        $exists = ReceivableRecord::query()
            ->where('service_order_id', $order->id)
            ->exists();
        if ($exists) {
            return;
        }

        ReceivableRecord::query()->create([
            'receivable_no' => sprintf('RCV%s%s', now()->format('YmdHis'), strtoupper(Str::random(4))),
            'service_order_id' => $order->id,
            'amount' => $order->amount,
            'received_amount' => 0,
            'currency' => $order->currency,
            'status' => 'PENDING',
            'due_at' => now()->addDays(7),
        ]);
    }

    private function ensureDeliveryObject(ServiceOrder $order): void
    {
        if ($order->project_id || $order->ticket_id) {
            return;
        }

        $mode = strtolower((string) ($order->delivery_mode ?: 'auto'));
        if ($mode === 'auto') {
            $mode = ((float) $order->amount >= 1000.0) ? 'project' : 'ticket';
        }

        if ($mode === 'project') {
            $project = Project::query()->create([
                'project_no' => sprintf('PRJ%s%s', now()->format('YmdHis'), strtoupper(Str::random(4))),
                'service_order_id' => $order->id,
                'name' => $order->service_name.' 项目交付',
                'status' => 'pending',
                'owner' => null,
                'meta_json' => ['auto_created' => true, 'source' => 'raw_mapping'],
            ]);
            $order->project_id = $project->id;
            $order->save();
            return;
        }

        $ticket = Ticket::query()->create([
            'ticket_no' => sprintf('TCK%s%s', now()->format('YmdHis'), strtoupper(Str::random(4))),
            'service_order_id' => $order->id,
            'title' => $order->service_name.' 工单',
            'status' => 'open',
            'assignee' => null,
            'meta_json' => ['auto_created' => true, 'source' => 'raw_mapping'],
        ]);
        $order->ticket_id = $ticket->id;
        $order->save();
    }

    private function isRefundEffective(string $status): bool
    {
        return in_array(strtoupper($status), ['APPROVED', 'PAID'], true);
    }

    private function applyReceivableDeltaByRefundTransition(
        ServiceOrder $order,
        ?string $oldStatus,
        string $newStatus,
        float $oldAmount,
        float $newAmount
    ): void {
        $oldEffective = $oldStatus !== null && $this->isRefundEffective($oldStatus);
        $newEffective = $this->isRefundEffective($newStatus);

        $delta = 0.0;
        if (! $oldEffective && $newEffective) {
            $delta = $newAmount;
        } elseif ($oldEffective && $newEffective) {
            $delta = $newAmount - $oldAmount;
        } elseif ($oldEffective && ! $newEffective) {
            $delta = 0 - $oldAmount;
        }

        if (abs($delta) < 0.00001) {
            return;
        }

        $receivable = ReceivableRecord::query()
            ->where('service_order_id', $order->id)
            ->orderByDesc('id')
            ->first();
        if (! $receivable) {
            $this->ensureReceivable($order);
            $receivable = ReceivableRecord::query()
                ->where('service_order_id', $order->id)
                ->orderByDesc('id')
                ->first();
            if (! $receivable) {
                return;
            }
        }

        $currentReceived = (float) $receivable->received_amount;
        $nextReceived = max(0.0, $currentReceived - $delta);
        $receivable->received_amount = $nextReceived;
        $receivable->status = $this->resolveReceivableStatus(
            (float) $receivable->amount,
            $nextReceived
        );
        $receivable->save();
    }

    private function upsertReconciliationByRefund(ServiceOrder $order, RefundRecord $refund): void
    {
        $record = ReconciliationRecord::query()
            ->where('refund_record_id', $refund->id)
            ->first();

        $status = $this->isRefundEffective((string) $refund->status) ? 'CLOSED' : 'OPEN';
        if (! $record) {
            ReconciliationRecord::query()->create([
                'reconciliation_no' => sprintf('REC%s%s', now()->format('YmdHis'), strtoupper(Str::random(4))),
                'service_order_id' => $order->id,
                'receivable_record_id' => ReceivableRecord::query()
                    ->where('service_order_id', $order->id)
                    ->orderByDesc('id')
                    ->value('id'),
                'refund_record_id' => $refund->id,
                'delta_amount' => 0 - (float) $refund->amount,
                'currency' => (string) $refund->currency,
                'status' => $status,
                'note' => 'refund mapped from raw channel data',
            ]);
            return;
        }

        $record->service_order_id = $order->id;
        $record->delta_amount = 0 - (float) $refund->amount;
        $record->currency = (string) $refund->currency;
        $record->status = $status;
        $record->note = 'refund mapped from raw channel data';
        $record->save();
    }

    private function resolveReceivableStatus(float $amount, float $receivedAmount): string
    {
        $safeAmount = max(0.0, $amount);
        $safeReceived = max(0.0, $receivedAmount);

        if ($safeAmount <= 0.0) {
            return 'PAID';
        }
        if ($safeReceived <= 0.0) {
            return 'PENDING';
        }
        if ($safeReceived + 0.00001 >= $safeAmount) {
            return 'PAID';
        }
        return 'PARTIAL';
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
