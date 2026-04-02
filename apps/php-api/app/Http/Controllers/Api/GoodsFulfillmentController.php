<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoodsOrderFulfillment;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GoodsFulfillmentController extends Controller
{
    public function index(Request $request)
    {
        $query = GoodsOrderFulfillment::query()
            ->with('order')
            ->orderByDesc('id');

        if ($request->filled('order_id')) {
            $query->where('order_id', (int) $request->integer('order_id'));
        }
        if ($request->filled('logistics_status')) {
            $query->where('logistics_status', strtolower($request->string('logistics_status')->toString()));
        }

        return ApiResponse::success($query->paginate(20));
    }

    public function updateStatus(Request $request, int $id)
    {
        $payload = $request->validate([
            'logistics_status' => ['required', 'string', 'in:pending,packed,shipped,in_transit,delivered,exception,cancelled'],
            'carrier' => ['nullable', 'string', 'max:64'],
            'tracking_no' => ['nullable', 'string', 'max:128'],
        ]);

        /** @var GoodsOrderFulfillment|null $fulfillment */
        $fulfillment = GoodsOrderFulfillment::query()->with('order')->find($id);
        if (! $fulfillment) {
            return ApiResponse::error('NOT_FOUND', 'goods fulfillment not found', 404);
        }

        $fulfillment = DB::transaction(function () use ($fulfillment, $payload): GoodsOrderFulfillment {
            $status = strtolower((string) $payload['logistics_status']);
            $fulfillment->logistics_status = $status;
            if (array_key_exists('carrier', $payload)) {
                $fulfillment->carrier = $payload['carrier'] ?? null;
            }
            if (array_key_exists('tracking_no', $payload)) {
                $fulfillment->tracking_no = $payload['tracking_no'] ?? null;
            }

            if ($status === 'shipped' && $fulfillment->shipped_at === null) {
                $fulfillment->shipped_at = now();
            }
            if ($status === 'delivered') {
                if ($fulfillment->shipped_at === null) {
                    $fulfillment->shipped_at = now();
                }
                if ($fulfillment->delivered_at === null) {
                    $fulfillment->delivered_at = now();
                }
            }

            $meta = is_array($fulfillment->meta_json) ? $fulfillment->meta_json : [];
            $meta['last_status_updated_at'] = now()->toDateTimeString();
            $meta['last_status_updated_from'] = 'goods_fulfillment_api';
            $fulfillment->meta_json = $meta;
            $fulfillment->save();

            $order = $fulfillment->order;
            if ($order && $order->order_type === 'goods') {
                if ($status === 'shipped' && $order->status !== 'shipped') {
                    $order->status = 'shipped';
                    $order->save();
                } elseif ($status === 'delivered' && $order->status !== 'completed') {
                    $order->status = 'completed';
                    if ($order->completed_at === null) {
                        $order->completed_at = now();
                    }
                    $order->save();
                }
            }

            return $fulfillment;
        });

        return ApiResponse::success($fulfillment->fresh('order'));
    }

    public function writebackPlaceholder(Request $request, int $id)
    {
        $payload = $request->validate([
            'channel_payload' => ['nullable', 'array'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var GoodsOrderFulfillment|null $fulfillment */
        $fulfillment = GoodsOrderFulfillment::query()->with('order')->find($id);
        if (! $fulfillment) {
            return ApiResponse::error('NOT_FOUND', 'goods fulfillment not found', 404);
        }

        $requestId = 'WBK-'.strtoupper(Str::random(12));
        $meta = is_array($fulfillment->meta_json) ? $fulfillment->meta_json : [];
        $meta['writeback_placeholder'] = [
            'request_id' => $requestId,
            'status' => 'QUEUED',
            'queued_at' => now()->toDateTimeString(),
            'channel_payload' => $payload['channel_payload'] ?? null,
            'note' => $payload['note'] ?? null,
        ];
        $fulfillment->meta_json = $meta;
        $fulfillment->save();

        return ApiResponse::success([
            'request_id' => $requestId,
            'message' => 'goods fulfillment writeback placeholder queued',
            'fulfillment' => $fulfillment->fresh('order'),
        ]);
    }

    public function pushShipmentPlaceholder(Request $request, int $id)
    {
        $payload = $request->validate([
            'carrier' => ['nullable', 'string', 'max:64'],
            'tracking_no' => ['nullable', 'string', 'max:128'],
            'channel_payload' => ['nullable', 'array'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var GoodsOrderFulfillment|null $fulfillment */
        $fulfillment = GoodsOrderFulfillment::query()->with('order')->find($id);
        if (! $fulfillment) {
            return ApiResponse::error('NOT_FOUND', 'goods fulfillment not found', 404);
        }

        $requestId = 'PSH-'.strtoupper(Str::random(12));
        $meta = is_array($fulfillment->meta_json) ? $fulfillment->meta_json : [];
        $meta['push_shipment_placeholder'] = [
            'request_id' => $requestId,
            'status' => 'QUEUED',
            'queued_at' => now()->toDateTimeString(),
            'carrier' => $payload['carrier'] ?? null,
            'tracking_no' => $payload['tracking_no'] ?? null,
            'channel_payload' => $payload['channel_payload'] ?? null,
            'note' => $payload['note'] ?? null,
        ];
        $fulfillment->meta_json = $meta;

        if (array_key_exists('carrier', $payload)) {
            $fulfillment->carrier = $payload['carrier'] ?? null;
        }
        if (array_key_exists('tracking_no', $payload)) {
            $fulfillment->tracking_no = $payload['tracking_no'] ?? null;
        }
        $fulfillment->save();

        return ApiResponse::success([
            'request_id' => $requestId,
            'message' => 'goods shipment push placeholder queued',
            'fulfillment' => $fulfillment->fresh('order'),
        ]);
    }
}
