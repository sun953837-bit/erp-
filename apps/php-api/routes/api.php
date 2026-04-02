<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChannelAccountController;
use App\Http\Controllers\Api\FinanceCenterController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\PlatformProductMappingController;
use App\Http\Controllers\Api\ProductSkuController;
use App\Http\Controllers\Api\ProductSpuController;
use App\Http\Controllers\Api\ServiceOrderController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\SyncReceiptLogController;
use App\Http\Controllers\Api\SyncTaskController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);

Route::prefix('/auth')->group(function () {
    Route::post('/send-sms-code', [AuthController::class, 'sendSmsCode']);
    Route::post('/verify-sms', [AuthController::class, 'verifySms']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::get('/shops', [ShopController::class, 'index']);
Route::post('/shops', [ShopController::class, 'store']);
Route::put('/shops/{id}', [ShopController::class, 'update']);

Route::get('/channel-accounts', [ChannelAccountController::class, 'index']);
Route::patch('/channel-accounts/{id}/auth-status', [ChannelAccountController::class, 'updateAuthStatus']);
Route::patch('/channel-accounts/by-shop/{shopId}/auth-status', [ChannelAccountController::class, 'upsertAuthStatusByShop']);

Route::get('/products/spu', [ProductSpuController::class, 'index']);
Route::post('/products/spu', [ProductSpuController::class, 'store']);
Route::get('/products/sku', [ProductSkuController::class, 'index']);
Route::post('/products/sku', [ProductSkuController::class, 'store']);

Route::get('/service-orders', [ServiceOrderController::class, 'index']);
Route::post('/service-orders', [ServiceOrderController::class, 'store']);
Route::get('/service-orders/{id}', [ServiceOrderController::class, 'show']);
Route::patch('/service-orders/{id}/status', [ServiceOrderController::class, 'updateStatus']);

Route::get('/finance/receivables', [FinanceCenterController::class, 'receivables']);
Route::get('/finance/payments', [FinanceCenterController::class, 'payments']);
Route::post('/finance/payments', [FinanceCenterController::class, 'createPayment']);
Route::get('/finance/refunds', [FinanceCenterController::class, 'refunds']);
Route::post('/finance/refunds', [FinanceCenterController::class, 'createRefund']);
Route::get('/finance/reconciliations', [FinanceCenterController::class, 'reconciliations']);

Route::get('/platform-product-mappings', [PlatformProductMappingController::class, 'index']);
Route::post('/platform-product-mappings', [PlatformProductMappingController::class, 'store']);

Route::post('/sync-tasks', [SyncTaskController::class, 'store']);
Route::get('/sync-tasks', [SyncTaskController::class, 'index']);
Route::get('/sync-tasks/{id}', [SyncTaskController::class, 'show']);
Route::post('/sync-tasks/{id}/run', [SyncTaskController::class, 'run']);
Route::post('/sync-tasks/{id}/retry', [SyncTaskController::class, 'retry']);
Route::get('/sync-tasks/{id}/receipts', [SyncReceiptLogController::class, 'indexByTask']);

Route::get('/webhooks/events', [WebhookController::class, 'index']);
Route::post('/webhooks/events/{id}/retry', [WebhookController::class, 'retry']);
Route::post('/webhooks/{platform}/events', [WebhookController::class, 'receive']);
