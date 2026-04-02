<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentRecord;
use App\Models\ReceivableRecord;
use App\Models\ReconciliationRecord;
use App\Models\RefundRecord;
use App\Models\ServiceOrder;
use App\Services\ChannelHub\Mapping\RawStatusMapper;
use App\Services\ChannelHub\Mapping\ServiceOrderDomainService;
use Illuminate\Support\Str;

class ServiceOrderDualWriteService
{
    public function __construct(
        private readonly ServiceOrderDomainService $serviceOrderDomainService,
        private readonly RawStatusMapper $statusMapper
    ) {
    }

    public function syncCanonicalFromServiceOrder(ServiceOrder $serviceOrder): Order
    {
        $order = $this->findCanonicalServiceOrder($serviceOrder);

        if (! $order) {
            $order = new Order();
            $order->order_no = (string) $serviceOrder->order_no;
            $order->order_type = 'service';
        }

        $order->platform_code = $serviceOrder->platform_code;
        $order->shop_id = $serviceOrder->shop_id;
        $order->external_order_id = $serviceOrder->external_order_id;
        $order->legacy_service_order_id = $serviceOrder->id;
        $order->project_id = $serviceOrder->project_id;
        $order->ticket_id = $serviceOrder->ticket_id;
        $order->subject = $serviceOrder->service_name;
        $order->customer_name = $serviceOrder->customer_name;
        $order->customer_id = $serviceOrder->customer_id;
        $order->currency = $serviceOrder->currency;
        $order->amount = $serviceOrder->amount;
        $order->status = $this->serviceStatusToCanonical((string) $serviceOrder->status);
        $order->delivery_mode = $serviceOrder->delivery_mode;
        $order->meta_json = $serviceOrder->meta_json;
        $order->confirmed_at = $serviceOrder->confirmed_at;
        $order->completed_at = $serviceOrder->completed_at;
        $order->save();

        $this->syncServiceOrderItem($order, $serviceOrder);
        $this->syncCanonicalFinanceSnapshot($serviceOrder, $order);

        return $order;
    }

    public function syncCanonicalFinanceSnapshot(ServiceOrder $serviceOrder, ?Order $order = null): ?Order
    {
        $targetOrder = $order;
        if (! $targetOrder) {
            $targetOrder = $this->findCanonicalServiceOrder($serviceOrder);
        }
        if (! $targetOrder) {
            return null;
        }

        $latestReceivable = ReceivableRecord::query()
            ->where('service_order_id', $serviceOrder->id)
            ->orderByDesc('id')
            ->first();
        $receivableCount = ReceivableRecord::query()
            ->where('service_order_id', $serviceOrder->id)
            ->count();

        $paymentCount = PaymentRecord::query()
            ->where('service_order_id', $serviceOrder->id)
            ->count();
        $refundCount = RefundRecord::query()
            ->where('service_order_id', $serviceOrder->id)
            ->count();
        $reconciliationCount = ReconciliationRecord::query()
            ->where('service_order_id', $serviceOrder->id)
            ->count();

        $meta = is_array($targetOrder->meta_json) ? $targetOrder->meta_json : [];
        $meta['finance_snapshot'] = [
            'service_order_id' => $serviceOrder->id,
            'receivable_count' => $receivableCount,
            'receivable_record_id' => $latestReceivable?->id,
            'receivable_status' => $latestReceivable?->status,
            'receivable_amount' => $latestReceivable !== null ? round((float) $latestReceivable->amount, 2) : null,
            'received_amount' => $latestReceivable !== null ? round((float) $latestReceivable->received_amount, 2) : null,
            'payment_count' => $paymentCount,
            'refund_count' => $refundCount,
            'reconciliation_count' => $reconciliationCount,
            'updated_at' => now()->toDateTimeString(),
        ];
        $targetOrder->meta_json = $meta;
        $targetOrder->save();

        return $targetOrder;
    }

    public function syncServiceFromCanonicalOrder(Order $order): ?ServiceOrder
    {
        if (strtolower((string) $order->order_type) !== 'service') {
            return null;
        }

        $serviceOrder = null;
        if ($order->legacy_service_order_id !== null) {
            $serviceOrder = ServiceOrder::query()->find((int) $order->legacy_service_order_id);
        }

        if (! $serviceOrder) {
            $serviceOrder = ServiceOrder::query()
                ->where('platform_code', $order->platform_code)
                ->where('shop_id', $order->shop_id)
                ->where('external_order_id', $order->external_order_id)
                ->first();
        }

        if (! $serviceOrder) {
            $serviceOrder = new ServiceOrder();
            $serviceOrder->order_no = (string) $order->order_no;
            $serviceOrder->delivery_mode = 'auto';
            $serviceOrder->status = 'pending';
        }

        $serviceOrder->platform_code = $order->platform_code;
        $serviceOrder->shop_id = $order->shop_id;
        $serviceOrder->external_order_id = $order->external_order_id;
        $serviceOrder->service_name = $order->subject ?: '渠道服务订单';
        $serviceOrder->customer_name = $order->customer_name;
        $serviceOrder->customer_id = $order->customer_id;
        $serviceOrder->currency = $order->currency;
        $serviceOrder->amount = $order->amount;
        $serviceOrder->status = $this->canonicalStatusToService((string) $order->status);
        $serviceOrder->delivery_mode = $this->canonicalDeliveryModeToService((string) $order->delivery_mode);
        $serviceOrder->project_id = $order->project_id;
        $serviceOrder->ticket_id = $order->ticket_id;
        $serviceOrder->meta_json = $order->meta_json;
        $serviceOrder->confirmed_at = $order->confirmed_at;
        $serviceOrder->completed_at = $order->completed_at;
        $serviceOrder->save();

        if ((int) ($order->legacy_service_order_id ?? 0) !== (int) $serviceOrder->id) {
            $order->legacy_service_order_id = $serviceOrder->id;
            $order->save();
        }

        if (
            $this->statusMapper->statusPriority((string) $serviceOrder->status)
            >= $this->statusMapper->statusPriority('confirmed')
        ) {
            $this->serviceOrderDomainService->ensureReceivable($serviceOrder);
            $this->serviceOrderDomainService->ensureDeliveryObject($serviceOrder);
        }

        $needsBackSync = false;
        if ((int) ($order->project_id ?? 0) !== (int) ($serviceOrder->project_id ?? 0)) {
            $order->project_id = $serviceOrder->project_id;
            $needsBackSync = true;
        }
        if ((int) ($order->ticket_id ?? 0) !== (int) ($serviceOrder->ticket_id ?? 0)) {
            $order->ticket_id = $serviceOrder->ticket_id;
            $needsBackSync = true;
        }
        if (trim((string) ($order->customer_id ?? '')) === '' && trim((string) ($serviceOrder->customer_id ?? '')) !== '') {
            $order->customer_id = $serviceOrder->customer_id;
            $needsBackSync = true;
        }
        if ($needsBackSync) {
            $order->save();
        }

        $this->syncServiceOrderItem($order, $serviceOrder);
        $this->syncCanonicalFinanceSnapshot($serviceOrder, $order);

        return $serviceOrder;
    }

    private function findCanonicalServiceOrder(ServiceOrder $serviceOrder): ?Order
    {
        return Order::query()
            ->where('order_type', 'service')
            ->where(function ($query) use ($serviceOrder): void {
                $query->where('legacy_service_order_id', $serviceOrder->id);

                if ($serviceOrder->external_order_id !== null) {
                    $query->orWhere(function ($subQuery) use ($serviceOrder): void {
                        $subQuery
                            ->where('platform_code', $serviceOrder->platform_code)
                            ->where('shop_id', $serviceOrder->shop_id)
                            ->where('external_order_id', $serviceOrder->external_order_id);
                    });
                }
            })
            ->orderByDesc('id')
            ->first();
    }

    private function syncServiceOrderItem(Order $order, ServiceOrder $serviceOrder): void
    {
        $item = OrderItem::query()
            ->where('order_id', $order->id)
            ->where('item_type', 'service')
            ->orderBy('id')
            ->first();

        if (! $item) {
            $item = new OrderItem();
            $item->order_id = $order->id;
            $item->item_no = sprintf('ITM%s%s', now()->format('YmdHis'), strtoupper(Str::random(4)));
            $item->item_type = 'service';
        }

        $meta = is_array($item->meta_json) ? $item->meta_json : [];
        $meta['source'] = 'service_dual_write';
        $meta['legacy_service_order_id'] = $serviceOrder->id;

        $item->sku_code = null;
        $item->title = $serviceOrder->service_name ?: ($order->subject ?: '渠道服务订单');
        $item->quantity = 1;
        $item->unit_price = round((float) $serviceOrder->amount, 2);
        $item->total_price = round((float) $serviceOrder->amount, 2);
        $item->currency = strtoupper((string) ($serviceOrder->currency ?: $order->currency ?: 'CNY'));
        $item->meta_json = $meta;
        $item->save();
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

    private function canonicalStatusToService(string $status): string
    {
        $value = strtolower(trim($status));
        return match ($value) {
            'pending' => 'pending',
            'confirmed' => 'confirmed',
            'in_delivery', 'shipped' => 'in_delivery',
            'completed' => 'completed',
            'after_sale' => 'after_sale',
            'closed', 'cancelled' => 'closed',
            default => 'pending',
        };
    }

    private function canonicalDeliveryModeToService(string $deliveryMode): string
    {
        $mode = strtolower(trim($deliveryMode));
        if (in_array($mode, ['auto', 'project', 'ticket'], true)) {
            return $mode;
        }

        return 'auto';
    }
}
