<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    public function index()
    {
        $items = Shop::query()->orderByDesc('id')->get();
        return ApiResponse::success($items);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'shop_code' => ['required', 'string', 'max:64', 'unique:shops,shop_code'],
            'shop_name' => ['required', 'string', 'max:255'],
            'platform_code' => ['required', 'string', 'max:64'],
            'site_code' => ['required', 'string', 'max:64'],
            'currency' => ['required', 'string', 'max:16'],
            'timezone' => ['required', 'string', 'max:64'],
            'status' => ['required', 'string', 'max:32'],
            'owner_name' => ['required', 'string', 'max:64'],
            'owner_phone' => ['required', 'string', 'max:32'],
        ]);

        $shop = Shop::query()->create($data);
        return ApiResponse::success($shop, 'success', 'OK', 201);
    }

    public function update(Request $request, int $id)
    {
        $shop = Shop::query()->find($id);
        if (! $shop) {
            return ApiResponse::error('NOT_FOUND', 'shop not found', 404);
        }

        $data = $request->validate([
            'shop_name' => ['sometimes', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', 'max:16'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'status' => ['sometimes', 'string', 'max:32'],
            'owner_name' => ['sometimes', 'string', 'max:64'],
            'owner_phone' => ['sometimes', 'string', 'max:32'],
        ]);

        $shop->fill($data);
        $shop->save();

        return ApiResponse::success($shop);
    }
}
