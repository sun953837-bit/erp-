<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChannelHub\RawChannelMappingService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class RawMappingController extends Controller
{
    public function summary(RawChannelMappingService $service)
    {
        return ApiResponse::success($service->summary());
    }

    public function run(Request $request, RawChannelMappingService $service)
    {
        $payload = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        $result = $service->run([
            'limit' => $payload['limit'] ?? 100,
        ]);

        return ApiResponse::success($result, 'raw channel mapping completed');
    }
}
