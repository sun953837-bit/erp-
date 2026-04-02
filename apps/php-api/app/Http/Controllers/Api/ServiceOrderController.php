<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ReceivableRecord;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServiceOrderController extends Controller
{
    private array $allowedStatuses = [
        'pending',
        'confirmed',
        'in_delivery',
        'completed',
        'after_sale',
        'closed',
    ];

    private array $allowedDeliveryModes = [
        'auto',
        'project',
        'ticket',
    ];

    private array $statusTransitions = [
        'pending' => ['confirmed', 'closed'],
        'confirmed' => ['in_delivery', 'after_sale', 'closed'],
        'in_delivery' => ['completed', 'after_sale', 'closed'],
        'completed' => ['after_sale', 'closed'],
        'after_sale' => ['closed'],
        'closed' => [],
    ];

    public function index(Request $request)
    {
        $status = strtolower($request->string('status')->toString());
        $query = ServiceOrder::query()->orderByDesc('id');
        if ($status !== '') {
            $query->where('status', $status);
        }
        return ApiResponse::success($query->paginate(20));
    }

    public function show(int $id)
    {
        $order = ServiceOrder::query()->find($id);
        if (! $order) {
            return ApiResponse::error('NOT_FOUND', 'service order not found', 404);
        }
        return ApiResponse::success($order);
    }

    public function store(Request $request)
    {
        $payload = $request->validate([
            'platform_code' => ['nullable', 'string', 'max:64'],
            'shop_id' => ['nullable', 'integer', 'exists:shops,id'],
            'external_order_id' => ['nullable', 'string', 'max:128'],
            'service_name' => ['required', 'string', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:128'],
            'currency' => ['nullable', 'string', 'max:16'],
            'amount' => ['required', 'numeric', 'min:0'],
            'delivery_mode' => ['nullable', 'string', 'in:auto,project,ticket'],
            'meta_json' => ['nullable', 'array'],
        ]);

        $orderNo = sprintf('SO%s%s', now()->format('YmdHis'), strtoupper(Str::random(4)));
        $order = ServiceOrder::query()->create([
            'order_no' => $orderNo,
            'platform_code' => $payload['platform_code'] ?? null,
            'shop_id' => $payload['shop_id'] ?? null,
            'external_order_id' => $payload['external_order_id'] ?? null,
            'service_name' => $payload['service_name'],
            'customer_name' => $payload['customer_name'] ?? null,
            'currency' => strtoupper((string) ($payload['currency'] ?? 'CNY')),
            'amount' => $payload['amount'],
            'status' => 'pending',
            'delivery_mode' => $payload['delivery_mode'] ?? 'auto',
            'meta_json' => $payload['meta_json'] ?? null,
            'confirmed_at' => null,
            'completed_at' => null,
        ]);

        return ApiResponse::success($order, 'success', 'OK', 201);
    }

    public function updateStatus(Request $request, int $id)
    {
        $payload = $request->validate([
            'status' => ['required', 'string', 'max:32'],
            'delivery_mode' => ['nullable', 'string', 'in:auto,project,ticket'],
        ]);

        $targetStatus = strtolower($payload['status']);
        if (! in_array($targetStatus, $this->allowedStatuses, true)) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'unsupported status, allowed: '.implode(', ', $this->allowedStatuses),
                422
            );
        }

        /** @var ServiceOrder|null $order */
        $order = ServiceOrder::query()->find($id);
        if (! $order) {
            return ApiResponse::error('NOT_FOUND', 'service order not found', 404);
        }

        if (! $this->isTransitionAllowed((string) $order->status, $targetStatus)) {
            return ApiResponse::error(
                'CONFLICT',
                sprintf('invalid status transition: %s -> %s', $order->status, $targetStatus),
                409
            );
        }

        $deliveryMode = isset($payload['delivery_mode']) ? strtolower($payload['delivery_mode']) : null;
        if ($deliveryMode !== null && ! in_array($deliveryMode, $this->allowedDeliveryModes, true)) {
            return ApiResponse::error('VALIDATION_ERROR', 'invalid delivery_mode', 422);
        }

        DB::transaction(function () use ($order, $targetStatus, $deliveryMode): void {
            if ($deliveryMode !== null) {
                $order->delivery_mode = $deliveryMode;
            }

            $order->status = $targetStatus;
            if ($targetStatus === 'confirmed' && $order->confirmed_at === null) {
                $order->confirmed_at = now();
            }
            if ($targetStatus === 'completed' && $order->completed_at === null) {
                $order->completed_at = now();
            }
            $order->save();

            if ($targetStatus === 'confirmed') {
                $this->ensureReceivable($order);
                $this->ensureDeliveryObject($order, $deliveryMode);
            }
        });

        $order->refresh();
        return ApiResponse::success($order);
    }

    private function ensureReceivable(ServiceOrder $order): void
    {
        $existing = ReceivableRecord::query()
            ->where('service_order_id', $order->id)
            ->exists();
        if ($existing) {
            return;
        }

        $receivableNo = sprintf('RCV%s%s', now()->format('YmdHis'), strtoupper(Str::random(4)));
        ReceivableRecord::query()->create([
            'receivable_no' => $receivableNo,
            'service_order_id' => $order->id,
            'amount' => $order->amount,
            'received_amount' => 0,
            'currency' => $order->currency,
            'status' => 'PENDING',
            'due_at' => now()->addDays(7),
        ]);
    }

    private function ensureDeliveryObject(ServiceOrder $order, ?string $deliveryMode): void
    {
        $mode = $deliveryMode ?? strtolower((string) $order->delivery_mode ?: 'auto');
        if ($mode === 'auto') {
            $mode = ((float) $order->amount >= 1000.0) ? 'project' : 'ticket';
        }

        if ($mode === 'project') {
            if ($order->project_id) {
                return;
            }
            $project = Project::query()->create([
                'project_no' => sprintf('PRJ%s%s', now()->format('YmdHis'), strtoupper(Str::random(4))),
                'service_order_id' => $order->id,
                'name' => $order->service_name.' 项目交付',
                'status' => 'pending',
                'owner' => null,
                'meta_json' => ['auto_created' => true],
            ]);
            $order->project_id = $project->id;
            $order->save();
            return;
        }

        if ($order->ticket_id) {
            return;
        }
        $ticket = Ticket::query()->create([
            'ticket_no' => sprintf('TCK%s%s', now()->format('YmdHis'), strtoupper(Str::random(4))),
            'service_order_id' => $order->id,
            'title' => $order->service_name.' 工单',
            'status' => 'open',
            'assignee' => null,
            'meta_json' => ['auto_created' => true],
        ]);
        $order->ticket_id = $ticket->id;
        $order->save();
    }

    private function isTransitionAllowed(string $from, string $to): bool
    {
        $fromKey = strtolower($from);
        if (! array_key_exists($fromKey, $this->statusTransitions)) {
            return false;
        }
        return in_array($to, $this->statusTransitions[$fromKey], true);
    }
}
