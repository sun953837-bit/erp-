<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Bi\BiEtlService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class BiEtlController extends Controller
{
    public function summary(BiEtlService $etlService)
    {
        return ApiResponse::success($etlService->summary());
    }

    public function refresh(Request $request, BiEtlService $etlService)
    {
        $payload = $request->validate([
            'mode' => ['sometimes', 'string', 'in:full,incremental,stage1'],
            'window_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
        ]);

        $result = $etlService->refresh([
            'mode' => $payload['mode'] ?? 'stage1',
            'window_days' => $payload['window_days'] ?? 3,
        ]);
        return ApiResponse::success($result, 'bi etl refresh completed');
    }

    public function recover(Request $request, BiEtlService $etlService)
    {
        $payload = $request->validate([
            'mode' => ['sometimes', 'string', 'in:full,incremental,stage1'],
            'window_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
        ]);

        $result = $etlService->recover([
            'mode' => $payload['mode'] ?? null,
            'window_days' => $payload['window_days'] ?? null,
        ]);

        return ApiResponse::success($result, 'bi etl recover completed');
    }
}
