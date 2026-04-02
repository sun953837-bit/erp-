<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoodsAfterSaleRecord;
use App\Models\Order;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GoodsAfterSaleController extends Controller
{
    public function index(Request $request)
    {
        $query = GoodsAfterSaleRecord::query()
            ->with(['order', 'orderItem'])
            ->orderByDesc('id');

        if ($request->filled('order_id')) {
            $query->where('order_id', (int) $request->integer('order_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->string('status')->toString()));
        }

        return ApiResponse::success($query->paginate(20));
    }

    public function store(Request $request)
    {
        $payload = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'order_item_id' => ['nullable', 'integer', 'exists:order_items,id'],
            'external_after_sale_id' => ['nullable', 'string', 'max:128'],
            'after_sale_type' => ['nullable', 'string', 'in:refund,return,exchange,complaint'],
            'status' => ['nullable', 'string', 'in:OPEN,PROCESSING,RESOLVED,REJECTED,CLOSED'],
            'reason' => ['nullable', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:16'],
            'requested_at' => ['nullable', 'date'],
            'meta_json' => ['nullable', 'array'],
        ]);

        /** @var Order $order */
        $order = Order::query()->findOrFail((int) $payload['order_id']);
        if ((string) $order->order_type !== 'goods') {
            return ApiResponse::error('VALIDATION_ERROR', 'after-sales baseline only supports goods order', 422);
        }
        if (! empty($payload['order_item_id'])) {
            $belongs = $order->items()->where('id', (int) $payload['order_item_id'])->exists();
            if (! $belongs) {
                return ApiResponse::error('VALIDATION_ERROR', 'order_item_id does not belong to order_id', 422);
            }
        }

        $record = DB::transaction(function () use ($payload, $order): GoodsAfterSaleRecord {
            $status = strtoupper((string) ($payload['status'] ?? 'OPEN'));
            $created = GoodsAfterSaleRecord::query()->create([
                'after_sale_no' => sprintf('GAS%s%s', now()->format('YmdHis'), strtoupper(Str::random(4))),
                'order_id' => $order->id,
                'order_item_id' => $payload['order_item_id'] ?? null,
                'external_after_sale_id' => $payload['external_after_sale_id'] ?? null,
                'after_sale_type' => strtolower((string) ($payload['after_sale_type'] ?? 'refund')),
                'status' => $status,
                'reason' => $payload['reason'] ?? null,
                'amount' => $payload['amount'] ?? 0,
                'currency' => strtoupper((string) ($payload['currency'] ?? $order->currency)),
                'requested_at' => $payload['requested_at'] ?? now(),
                'resolved_at' => in_array($status, ['RESOLVED', 'REJECTED', 'CLOSED'], true) ? now() : null,
                'meta_json' => $payload['meta_json'] ?? null,
            ]);

            if (! in_array($status, ['RESOLVED', 'REJECTED', 'CLOSED'], true) && (string) $order->status !== 'after_sale') {
                $order->status = 'after_sale';
                $order->save();
            }

            return $created;
        });

        return ApiResponse::success($record->fresh(['order', 'orderItem']), 'success', 'OK', 201);
    }

    public function updateStatus(Request $request, int $id)
    {
        $payload = $request->validate([
            'status' => ['required', 'string', 'in:OPEN,PROCESSING,RESOLVED,REJECTED,CLOSED'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var GoodsAfterSaleRecord|null $record */
        $record = GoodsAfterSaleRecord::query()->find($id);
        if (! $record) {
            return ApiResponse::error('NOT_FOUND', 'goods after-sale record not found', 404);
        }

        $record = DB::transaction(function () use ($record, $payload): GoodsAfterSaleRecord {
            $status = strtoupper((string) $payload['status']);
            $record->status = $status;

            $meta = is_array($record->meta_json) ? $record->meta_json : [];
            if (! empty($payload['note'])) {
                $meta['last_status_note'] = (string) $payload['note'];
            }
            $meta['last_status_updated_at'] = now()->toDateTimeString();
            $record->meta_json = $meta;
            $record->resolved_at = in_array($status, ['RESOLVED', 'REJECTED', 'CLOSED'], true) ? now() : null;
            $record->save();

            $order = $record->order;
            if ($order && $order->order_type === 'goods') {
                if (! in_array($status, ['RESOLVED', 'REJECTED', 'CLOSED'], true)) {
                    $order->status = 'after_sale';
                    $order->save();
                }
            }

            return $record;
        });

        return ApiResponse::success($record->fresh(['order', 'orderItem']));
    }
}
