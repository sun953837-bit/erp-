<?php

namespace App\Services\ChannelHub\Mapping;

class RawStatusMapper
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

    public function normalizeOrderStatus(string $status): string
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

    public function normalizeRefundStatus(string $status): string
    {
        $value = strtolower(trim($status));
        return match ($value) {
            'approved', 'pass' => 'APPROVED',
            'paid', 'finished', 'success', 'completed' => 'PAID',
            'rejected', 'reject', 'failed' => 'REJECTED',
            default => 'PENDING',
        };
    }

    public function statusPriority(string $status): int
    {
        $key = strtolower($status);
        return $this->orderStatusPriority[$key] ?? 0;
    }

    public function isRefundEffective(string $status): bool
    {
        return in_array(strtoupper($status), ['APPROVED', 'PAID'], true);
    }
}
