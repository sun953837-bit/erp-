<?php

namespace App\Services\ChannelHub\Mapping;

use App\Models\ReceivableRecord;
use App\Models\ReconciliationRecord;
use App\Models\RefundRecord;
use App\Models\ServiceOrder;
use Illuminate\Support\Str;

class RefundDomainService
{
    public function applyReceivableDeltaByRefundTransition(
        ServiceOrder $order,
        ?string $oldStatus,
        string $newStatus,
        float $oldAmount,
        float $newAmount,
        RawStatusMapper $statusMapper,
        ServiceOrderDomainService $serviceOrderDomain
    ): void {
        $oldEffective = $oldStatus !== null && $statusMapper->isRefundEffective($oldStatus);
        $newEffective = $statusMapper->isRefundEffective($newStatus);

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
            $serviceOrderDomain->ensureReceivable($order);
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

    public function upsertReconciliationByRefund(ServiceOrder $order, RefundRecord $refund, RawStatusMapper $statusMapper): void
    {
        $record = ReconciliationRecord::query()
            ->where('refund_record_id', $refund->id)
            ->first();

        $status = $statusMapper->isRefundEffective((string) $refund->status) ? 'CLOSED' : 'OPEN';
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
}
