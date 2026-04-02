<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\ServiceOrder;
use App\Services\ChannelHub\Mapping\RawStatusMapper;
use App\Services\ChannelHub\Mapping\ServiceOrderDomainService;

class ServiceOrderDualWriteService
{
    public function __construct(
        private readonly ServiceOrderDomainService $serviceOrderDomainService,
        private readonly RawStatusMapper $statusMapper
    ) {
    }

    public function syncCanonicalFromServiceOrder(ServiceOrder $serviceOrder): Order
    {
        $order = Order::query()
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

        if (! $order) {
            $order = new Order();
            $order->order_no = (string) $serviceOrder->order_no;
            $order->order_type = 'service';
        }

        $order->platform_code = $serviceOrder->platform_code;
        $order->shop_id = $serviceOrder->shop_id;
        $order->external_order_id = $serviceOrder->external_order_id;
        $order->legacy_service_order_id = $serviceOrder->id;
        $order->subject = $serviceOrder->service_name;
        $order->customer_name = $serviceOrder->customer_name;
        $order->currency = $serviceOrder->currency;
        $order->amount = $serviceOrder->amount;
        $order->status = $this->serviceStatusToCanonical((string) $serviceOrder->status);
        $order->delivery_mode = $serviceOrder->delivery_mode;
        $order->meta_json = $serviceOrder->meta_json;
        $order->confirmed_at = $serviceOrder->confirmed_at;
        $order->completed_at = $serviceOrder->completed_at;
        $order->save();

        return $order;
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
        $serviceOrder->currency = $order->currency;
        $serviceOrder->amount = $order->amount;
        $serviceOrder->status = $this->canonicalStatusToService((string) $order->status);
        $serviceOrder->delivery_mode = $this->canonicalDeliveryModeToService((string) $order->delivery_mode);
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

        return $serviceOrder;
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
