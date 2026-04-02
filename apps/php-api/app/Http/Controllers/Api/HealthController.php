<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Support\Carbon;

class HealthController extends Controller
{
    public function index()
    {
        return ApiResponse::success([
            'service' => 'php-api',
            'time' => Carbon::now()->toIso8601String(),
        ]);
    }
}
