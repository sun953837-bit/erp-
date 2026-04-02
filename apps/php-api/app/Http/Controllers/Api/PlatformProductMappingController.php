<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformProductMapping;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class PlatformProductMappingController extends Controller
{
    public function index()
    {
        $items = PlatformProductMapping::query()->orderByDesc('id')->get();
        return ApiResponse::success($items);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'spu_id' => ['nullable', 'integer', 'exists:products_spu,id'],
            'sku_id' => ['required', 'integer', 'exists:products_sku,id'],
            'platform_code' => ['required', 'string', 'max:64'],
            'site_code' => ['required', 'string', 'max:64'],
            'external_listing_id' => ['nullable', 'string', 'max:128'],
            'external_sku_id' => ['nullable', 'string', 'max:128'],
            'external_status' => ['nullable', 'string', 'max:64'],
            'raw_payload' => ['nullable', 'array'],
            'last_synced_at' => ['nullable', 'date'],
        ]);

        $exists = PlatformProductMapping::query()
            ->where('shop_id', $data['shop_id'])
            ->where('sku_id', $data['sku_id'])
            ->where('platform_code', $data['platform_code'])
            ->where('site_code', $data['site_code'])
            ->exists();

        if ($exists) {
            return ApiResponse::error('CONFLICT', 'mapping already exists', 409);
        }

        $item = PlatformProductMapping::query()->create($data);
        return ApiResponse::success($item, 'success', 'OK', 201);
    }
}
