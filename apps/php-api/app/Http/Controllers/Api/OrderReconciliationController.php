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
        ]);

        $result = $service->reconcile([
            'sample_limit' => $payload['sample_limit'] ?? 50,
        ]);

        return ApiResponse::success($result);
    }
}
