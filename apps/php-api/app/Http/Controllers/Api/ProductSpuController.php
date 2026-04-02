<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductSpu;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ProductSpuController extends Controller
{
    public function index()
    {
        $items = ProductSpu::query()->orderByDesc('id')->get();
        return ApiResponse::success($items);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'spu_code' => ['required', 'string', 'max:64', 'unique:products_spu,spu_code'],
            'title' => ['required', 'string', 'max:255'],
            'brand' => ['required', 'string', 'max:128'],
            'category_name' => ['required', 'string', 'max:128'],
            'status' => ['required', 'string', 'max:32'],
            'source_of_truth' => ['sometimes', 'string', 'max:32'],
        ]);

        $data['source_of_truth'] = $data['source_of_truth'] ?? 'system';
        $item = ProductSpu::query()->create($data);
        return ApiResponse::success($item, 'success', 'OK', 201);
    }
}
