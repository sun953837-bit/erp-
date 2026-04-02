<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductSku;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ProductSkuController extends Controller
{
    public function index()
    {
        $items = ProductSku::query()->orderByDesc('id')->get();
        return ApiResponse::success($items);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sku_code' => ['required', 'string', 'max:64', 'unique:products_sku,sku_code'],
            'spu_id' => ['required', 'integer', 'exists:products_spu,id'],
            'sku_name' => ['required', 'string', 'max:255'],
            'specs_json' => ['required', 'array'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'cost_price' => ['required', 'numeric'],
            'cost_currency' => ['required', 'string', 'max:16'],
            'retail_price' => ['required', 'numeric'],
            'retail_currency' => ['required', 'string', 'max:16'],
            'weight' => ['nullable', 'numeric'],
            'size_json' => ['nullable', 'array'],
            'status' => ['required', 'string', 'max:32'],
        ]);

        $item = ProductSku::query()->create($data);
        return ApiResponse::success($item, 'success', 'OK', 201);
    }
}
