<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoodsOrderFulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Order\ServiceOrderDualWriteService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function __construct(
        private readonly ServiceOrderDualWriteService $dualWrite
    ) {
    }

    private array $allowedOrderTypes = [
        'service',
        'goods',
    ];

    private array $allowedStatuses = [
        'pending',
        'confirmed',
        'in_delivery',
        'shipped',
        'completed',
        'after_sale',
        'closed',
        'cancelled',
    ];

    private array $statusTransitions = [
        'pending' => ['confirmed', 'cancelled', 'closed'],
        'confirmed' => ['in_delivery', 'shipped', 'after_sale', 'cancelled', 'closed'],
        'in_delivery' => ['completed', 'after_sale', 'closed'],
        'shipped' => ['completed', 'after_sale', 'closed'],
        'completed' => ['after_sale', 'closed'],
        'after_sale' => ['closed'],
        'cancelled' => [],
        'closed' => [],
    ];

    private array $legacyAliasKeys = [
        'service_order_id',
        'goods_order_id',
        'unified_order_id',
        'platform_accounts',
        'service_catalog',
    ];

    public function index(Request $request)
    {
        $orderType = strtolower($request->string('order_type')->toString());
        $status = strtolower($request->string('status')->toString());

        $query = Order::query()->with(['items', 'goodsFulfillments', 'goodsAfterSales'])->orderByDesc('id');
        if ($orderType !== '') {
            $query->where('order_type', $orderType);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($request->filled('shop_id')) {
            $query->where('shop_id', (int) $request->integer('shop_id'));
        }

        return ApiResponse::success($query->paginate(20));
    }

    public function indexGoods(Request $request)
    {
        $request->merge(['order_type' => 'goods']);
        return $this->index($request);
    }

    public function show(int $id)
    {
        $order = Order::query()->with(['items', 'goodsFulfillments', 'goodsAfterSales'])->find($id);
        if (! $order) {
            return ApiResponse::error('NOT_FOUND', 'order not found', 404);
        }
        return ApiResponse::success($order);
    }

    public function store(Request $request)
    {
        return $this->storeInternal($request);
    }

    public function storeGoods(Request $request)
    {
        $request->merge(['order_type' => 'goods']);
        return $this->storeInternal($request);
    }

    public function updateStatus(Request $request, int $id)
    {
        $payload = $request->validate([
            'status' => ['required', 'string', 'max:32'],
        ]);

        $targetStatus = strtolower($payload['status']);
        if (! in_array($targetStatus, $this->allowedStatuses, true)) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'unsupported status, allowed: '.implode(', ', $this->allowedStatuses),
                422
            );
        }

        $order = Order::query()->find($id);
        if (! $order) {
            return ApiResponse::error('NOT_FOUND', 'order not found', 404);
        }

        if (! $this->isTransitionAllowed((string) $order->status, $targetStatus)) {
            return ApiResponse::error(
                'CONFLICT',
                sprintf('invalid status transition: %s -> %s', $order->status, $targetStatus),
                409
            );
        }

        DB::transaction(function () use ($order, $targetStatus): void {
            $order->status = $targetStatus;
            if ($targetStatus === 'confirmed' && $order->confirmed_at === null) {
                $order->confirmed_at = now();
            }
            if ($targetStatus === 'completed' && $order->completed_at === null) {
                $order->completed_at = now();
            }
            $order->save();

            if ($order->order_type === 'goods' && in_array($targetStatus, ['confirmed', 'shipped'], true)) {
                $this->ensureGoodsFulfillment($order);
            }

            if ($order->order_type === 'service' && $this->isDualWriteEnabled()) {
                $this->dualWrite->syncServiceFromCanonicalOrder($order);
            }
        });

        $order->load(['items', 'goodsFulfillments', 'goodsAfterSales']);
        return ApiResponse::success($order);
    }

    private function storeInternal(Request $request)
    {
        $payload = $request->validate([
            'order_type' => ['required', 'string', 'in:service,goods'],
            'platform_code' => ['nullable', 'string', 'max:64'],
            'shop_id' => ['nullable', 'integer', 'exists:shops,id'],
            'external_order_id' => ['nullable', 'string', 'max:128'],
            'subject' => ['required', 'string', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:128'],
            'customer_id' => ['nullable', 'string', 'max:128'],
            'currency' => ['nullable', 'string', 'max:16'],
            'amount' => ['required', 'numeric', 'min:0'],
            'status' => ['nullable', 'string', 'max:32'],
            'delivery_mode' => ['nullable', 'string', 'max:32'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'ticket_id' => ['nullable', 'integer', 'exists:tickets,id'],
            'meta_json' => ['nullable', 'array'],
            'items' => ['nullable', 'array'],
            'items.*.item_type' => ['nullable', 'string', 'max:32'],
            'items.*.sku_code' => ['nullable', 'string', 'max:128'],
            'items.*.title' => ['required_with:items', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.currency' => ['nullable', 'string', 'max:16'],
            'items.*.meta_json' => ['nullable', 'array'],
        ]);

        $forbiddenAliasKeys = array_values(
            array_filter(
                $this->legacyAliasKeys,
                static fn (string $key): bool => array_key_exists($key, $request->all())
            )
        );
        if (count($forbiddenAliasKeys) > 0) {
            return ApiResponse::error(
                'LEGACY_ALIAS_FORBIDDEN',
                'legacy alias fields are frozen on canonical write path',
                422,
                ['forbidden_keys' => $forbiddenAliasKeys]
            );
        }

        $orderType = strtolower((string) $payload['order_type']);
        if (! in_array($orderType, $this->allowedOrderTypes, true)) {
            return ApiResponse::error('VALIDATION_ERROR', 'unsupported order_type', 422);
        }

        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if ($orderType === 'goods' && count($items) === 0) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'goods order requires items[] baseline payload',
                422
            );
        }

        $targetStatus = strtolower((string) ($payload['status'] ?? 'pending'));
        if (! in_array($targetStatus, $this->allowedStatuses, true)) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'unsupported status, allowed: '.implode(', ', $this->allowedStatuses),
                422
            );
        }

        $order = DB::transaction(function () use ($payload, $items, $orderType, $targetStatus): Order {
            $currency = strtoupper((string) ($payload['currency'] ?? 'CNY'));
            $order = Order::query()->create([
                'order_no' => sprintf('ORD%s%s', now()->format('YmdHis'), strtoupper(Str::random(4))),
                'order_type' => $orderType,
                'platform_code' => $payload['platform_code'] ?? null,
                'shop_id' => $payload['shop_id'] ?? null,
                'external_order_id' => $payload['external_order_id'] ?? null,
                'legacy_service_order_id' => null,
                'project_id' => $orderType === 'service' ? ($payload['project_id'] ?? null) : null,
                'ticket_id' => $orderType === 'service' ? ($payload['ticket_id'] ?? null) : null,
                'subject' => $payload['subject'],
                'customer_name' => $payload['customer_name'] ?? null,
                'customer_id' => $payload['customer_id'] ?? null,
                'currency' => $currency,
                'amount' => round((float) $payload['amount'], 2),
                'status' => $targetStatus,
                'delivery_mode' => $payload['delivery_mode'] ?? ($orderType === 'goods' ? 'shipment' : 'auto'),
                'meta_json' => $payload['meta_json'] ?? null,
                'confirmed_at' => $targetStatus === 'confirmed' ? now() : null,
                'completed_at' => $targetStatus === 'completed' ? now() : null,
            ]);

            if (count($items) === 0) {
                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'item_no' => sprintf('ITM%s%s', now()->format('YmdHis'), strtoupper(Str::random(4))),
                    'item_type' => $orderType,
                    'sku_code' => null,
                    'title' => (string) $payload['subject'],
                    'quantity' => 1,
                    'unit_price' => round((float) $payload['amount'], 2),
                    'total_price' => round((float) $payload['amount'], 2),
                    'currency' => $currency,
                    'meta_json' => null,
                ]);
            } else {
                foreach ($items as $item) {
                    $quantity = max(1, (int) ($item['quantity'] ?? 1));
                    $unitPrice = round((float) ($item['unit_price'] ?? 0), 2);
                    OrderItem::query()->create([
                        'order_id' => $order->id,
                        'item_no' => sprintf('ITM%s%s', now()->format('YmdHis'), strtoupper(Str::random(4))),
                        'item_type' => strtolower((string) ($item['item_type'] ?? $orderType)),
                        'sku_code' => $item['sku_code'] ?? null,
                        'title' => (string) ($item['title'] ?? $payload['subject']),
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'total_price' => round($unitPrice * $quantity, 2),
                        'currency' => strtoupper((string) ($item['currency'] ?? $currency)),
                        'meta_json' => $item['meta_json'] ?? null,
                    ]);
                }
            }

            if ($orderType === 'goods' && in_array($targetStatus, ['confirmed', 'shipped'], true)) {
                $this->ensureGoodsFulfillment($order);
            }

            if ($orderType === 'service' && $this->isDualWriteEnabled()) {
                $this->dualWrite->syncServiceFromCanonicalOrder($order);
            }

            return $order;
        });

        $order->load(['items', 'goodsFulfillments', 'goodsAfterSales']);
        return ApiResponse::success($order, 'success', 'OK', 201);
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
            'meta_json' => ['baseline_reserved' => true],
        ]);
    }

    private function isTransitionAllowed(string $from, string $to): bool
    {
        $fromKey = strtolower($from);
        if (! array_key_exists($fromKey, $this->statusTransitions)) {
            return false;
        }
        return in_array($to, $this->statusTransitions[$fromKey], true);
    }

    private function isDualWriteEnabled(): bool
    {
        return filter_var((string) env('SERVICE_ORDER_DUAL_WRITE_ENABLED', 'true'), FILTER_VALIDATE_BOOL);
    }
}
