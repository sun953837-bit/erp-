<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentRecord;
use App\Models\ReceivableRecord;
use App\Models\ReconciliationRecord;
use App\Models\RefundRecord;
use App\Models\ServiceOrder;
use App\Services\Order\ServiceOrderDualWriteService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceCenterController extends Controller
{
    public function receivables(Request $request)
    {
        $status = strtoupper($request->string('status')->toString());
        $query = ReceivableRecord::query()->orderByDesc('id');
        if ($status !== '') {
            $query->where('status', $status);
        }
        return ApiResponse::success($query->paginate(20));
    }

    public function payments(Request $request)
    {
        $query = PaymentRecord::query()->orderByDesc('id');
        if ($request->filled('service_order_id')) {
            $query->where('service_order_id', (int) $request->input('service_order_id'));
        }
        return ApiResponse::success($query->paginate(20));
    }

    public function refunds(Request $request)
    {
        $status = strtoupper($request->string('status')->toString());
        $query = RefundRecord::query()->orderByDesc('id');
        if ($status !== '') {
            $query->where('status', $status);
        }
        return ApiResponse::success($query->paginate(20));
    }

    public function reconciliations(Request $request)
    {
        $status = strtoupper($request->string('status')->toString());
        $query = ReconciliationRecord::query()->orderByDesc('id');
        if ($status !== '') {
            $query->where('status', $status);
        }
        return ApiResponse::success($query->paginate(20));
    }

    public function createPayment(Request $request, ServiceOrderDualWriteService $dualWrite)
    {
        $payload = $request->validate([
            'service_order_id' => ['required', 'integer', 'exists:service_orders,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'max:16'],
            'paid_at' => ['nullable', 'date'],
            'channel' => ['nullable', 'string', 'max:64'],
            'reference_no' => ['nullable', 'string', 'max:128'],
            'note' => ['nullable', 'string'],
        ]);

        $result = DB::transaction(function () use ($payload, $dualWrite): array {
            /** @var ServiceOrder $order */
            $order = ServiceOrder::query()->findOrFail((int) $payload['service_order_id']);
            /** @var ReceivableRecord|null $receivable */
            $receivable = ReceivableRecord::query()
                ->where('service_order_id', $order->id)
                ->orderByDesc('id')
                ->first();
            if (! $receivable) {
                throw new \RuntimeException('receivable record not found for this service order');
            }

            $payment = PaymentRecord::query()->create([
                'payment_no' => sprintf('PAY%s%s', now()->format('YmdHis'), strtoupper(Str::random(4))),
                'service_order_id' => $order->id,
                'receivable_record_id' => $receivable->id,
                'amount' => $payload['amount'],
                'currency' => strtoupper((string) ($payload['currency'] ?? $receivable->currency)),
                'paid_at' => isset($payload['paid_at']) ? $payload['paid_at'] : now(),
                'channel' => $payload['channel'] ?? null,
                'reference_no' => $payload['reference_no'] ?? null,
                'note' => $payload['note'] ?? null,
            ]);

            $receivable->received_amount = (float) $receivable->received_amount + (float) $payment->amount;
            $receivable->status = $this->resolveReceivableStatus(
                (float) $receivable->amount,
                (float) $receivable->received_amount
            );
            $receivable->save();

            if (
                in_array((string) $receivable->status, ['PARTIAL', 'PAID'], true)
                && strtolower((string) $order->status) === 'pending'
            ) {
                $order->status = 'confirmed';
                if ($order->confirmed_at === null) {
                    $order->confirmed_at = now();
                }
                $order->save();
            }

            ReconciliationRecord::query()->create([
                'reconciliation_no' => sprintf('REC%s%s', now()->format('YmdHis'), strtoupper(Str::random(4))),
                'service_order_id' => $order->id,
                'receivable_record_id' => $receivable->id,
                'refund_record_id' => null,
                'delta_amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => 'CLOSED',
                'note' => 'payment recorded',
            ]);

            if ($this->isDualWriteEnabled()) {
                $canonical = $dualWrite->syncCanonicalFromServiceOrder($order);
                $dualWrite->syncCanonicalFinanceSnapshot($order, $canonical);
            }

            return [
                'payment' => $payment,
                'receivable' => $receivable,
            ];
        });

        return ApiResponse::success($result, 'payment recorded');
    }

    public function createRefund(Request $request, ServiceOrderDualWriteService $dualWrite)
    {
        $payload = $request->validate([
            'service_order_id' => ['required', 'integer', 'exists:service_orders,id'],
            'payment_record_id' => ['nullable', 'integer', 'exists:payment_records,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'max:16'],
            'status' => ['nullable', 'string', 'in:PENDING,APPROVED,PAID,REJECTED'],
            'reason' => ['nullable', 'string', 'max:255'],
            'refunded_at' => ['nullable', 'date'],
        ]);

        $result = DB::transaction(function () use ($payload, $dualWrite): array {
            /** @var ServiceOrder $order */
            $order = ServiceOrder::query()->findOrFail((int) $payload['service_order_id']);
            /** @var ReceivableRecord|null $receivable */
            $receivable = ReceivableRecord::query()
                ->where('service_order_id', $order->id)
                ->orderByDesc('id')
                ->first();

            $refundStatus = strtoupper((string) ($payload['status'] ?? 'PAID'));
            $refund = RefundRecord::query()->create([
                'refund_no' => sprintf('RFD%s%s', now()->format('YmdHis'), strtoupper(Str::random(4))),
                'service_order_id' => $order->id,
                'payment_record_id' => $payload['payment_record_id'] ?? null,
                'amount' => $payload['amount'],
                'currency' => strtoupper((string) ($payload['currency'] ?? ($receivable?->currency ?? $order->currency))),
                'status' => $refundStatus,
                'reason' => $payload['reason'] ?? null,
                'refunded_at' => isset($payload['refunded_at']) ? $payload['refunded_at'] : now(),
            ]);

            if ($receivable && in_array($refundStatus, ['APPROVED', 'PAID'], true)) {
                $receivable->received_amount = max(
                    0.0,
                    (float) $receivable->received_amount - (float) $refund->amount
                );
                $receivable->status = $this->resolveReceivableStatus(
                    (float) $receivable->amount,
                    (float) $receivable->received_amount
                );
                $receivable->save();
            }

            if (
                in_array($refundStatus, ['APPROVED', 'PAID'], true)
                && strtolower((string) $order->status) !== 'after_sale'
            ) {
                $order->status = 'after_sale';
                $order->save();
            }

            ReconciliationRecord::query()->create([
                'reconciliation_no' => sprintf('REC%s%s', now()->format('YmdHis'), strtoupper(Str::random(4))),
                'service_order_id' => $order->id,
                'receivable_record_id' => $receivable?->id,
                'refund_record_id' => $refund->id,
                'delta_amount' => 0 - (float) $refund->amount,
                'currency' => $refund->currency,
                'status' => in_array($refundStatus, ['APPROVED', 'PAID'], true) ? 'CLOSED' : 'OPEN',
                'note' => 'refund recorded',
            ]);

            if ($this->isDualWriteEnabled()) {
                $canonical = $dualWrite->syncCanonicalFromServiceOrder($order);
                $dualWrite->syncCanonicalFinanceSnapshot($order, $canonical);
            }

            return [
                'refund' => $refund,
                'receivable' => $receivable,
            ];
        });

        return ApiResponse::success($result, 'refund recorded');
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

    private function isDualWriteEnabled(): bool
    {
        return filter_var((string) env('SERVICE_ORDER_DUAL_WRITE_ENABLED', 'true'), FILTER_VALIDATE_BOOL);
    }
}
