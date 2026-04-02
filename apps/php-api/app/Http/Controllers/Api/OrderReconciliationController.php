<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Order\ServiceOrderReconciliationService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class OrderReconciliationController extends Controller
{
    public function service(Request $request, ServiceOrderReconciliationService $service)
    {
        $payload = $request->validate([
            'sample_limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'platform_code' => ['sometimes', 'string', 'max:64'],
            'shop_id' => ['sometimes', 'integer', 'exists:shops,id'],
            'account_id' => ['sometimes', 'integer', 'exists:shops,id'],
        ]);

        $result = $service->reconcile([
            'sample_limit' => $payload['sample_limit'] ?? 50,
            'date_from' => $payload['date_from'] ?? null,
            'date_to' => $payload['date_to'] ?? null,
            'platform_code' => $payload['platform_code'] ?? null,
            'shop_id' => $payload['shop_id'] ?? ($payload['account_id'] ?? null),
        ]);

        return ApiResponse::success($result);
    }
}
