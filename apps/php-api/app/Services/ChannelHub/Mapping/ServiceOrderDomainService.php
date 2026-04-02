<?php

namespace App\Services\ChannelHub\Mapping;

use App\Models\Project;
use App\Models\ReceivableRecord;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use Illuminate\Support\Str;

class ServiceOrderDomainService
{
    public function applyMappedStatus(
        ServiceOrder $order,
        string $targetStatus,
        RawStatusMapper $statusMapper
    ): void {
        $currentPriority = $statusMapper->statusPriority((string) $order->status);
        $targetPriority = $statusMapper->statusPriority($targetStatus);

        if ($targetPriority >= $currentPriority) {
            $order->status = $targetStatus;
        }

        if (
            $statusMapper->statusPriority((string) $order->status) >= $statusMapper->statusPriority('confirmed')
            && $order->confirmed_at === null
        ) {
            $order->confirmed_at = now();
        }

        if (
            $statusMapper->statusPriority((string) $order->status) >= $statusMapper->statusPriority('completed')
            && $order->completed_at === null
        ) {
            $order->completed_at = now();
        }
    }

    public function ensureReceivable(ServiceOrder $order): void
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

    public function ensureDeliveryObject(ServiceOrder $order): void
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
}
