<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\ShopPlatformConfig;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChannelAccountController extends Controller
{
    private array $allowedStatus = [
        'PENDING',
        'AUTHORIZED',
        'EXPIRED',
        'REVOKED',
        'ERROR',
    ];

    public function index(Request $request)
    {
        $status = strtoupper($request->string('status')->toString());
        $platformCode = strtolower($request->string('platform_code')->toString());

        $query = ShopPlatformConfig::query()
            ->with(['shop:id,shop_code,shop_name,platform_code,site_code,status']);

        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($platformCode !== '') {
            $query->whereHas(
                'shop',
                static fn($shopQuery) => $shopQuery->where('platform_code', $platformCode)
            );
        }

        $items = $query->orderByDesc('id')->get()->map(
            static function (ShopPlatformConfig $config): array {
                $shop = $config->shop;
                return [
                    'id' => $config->id,
                    'shop_id' => $config->shop_id,
                    'platform_code' => $shop?->platform_code,
                    'site_code' => $shop?->site_code,
                    'shop_code' => $shop?->shop_code,
                    'shop_name' => $shop?->shop_name,
                    'auth_mode' => $config->auth_mode,
                    'status' => $config->status,
                    'is_configured' => $config->is_configured,
                    'key_version' => $config->key_version,
                    'updated_at' => optional($config->updated_at)?->toDateTimeString(),
                ];
            }
        );

        return ApiResponse::success($items);
    }

    public function updateAuthStatus(Request $request, int $id)
    {
        $config = ShopPlatformConfig::query()->find($id);
        if (! $config) {
            return ApiResponse::error('NOT_FOUND', 'channel account not found', 404);
        }

        $payload = $request->validate([
            'status' => ['required', 'string', 'max:32'],
            'is_configured' => ['sometimes', 'boolean'],
            'auth_mode' => ['sometimes', 'string', 'max:32'],
            'client_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'refresh_token_encrypted' => ['sometimes', 'nullable', 'string'],
            'key_version' => ['sometimes', 'integer', 'min:1', 'max:9999'],
        ]);

        return $this->applyAuthUpdate($config, $payload);
    }

    public function upsertAuthStatusByShop(Request $request, int $shopId)
    {
        $shop = Shop::query()->find($shopId);
        if (! $shop) {
            return ApiResponse::error('NOT_FOUND', 'shop not found', 404);
        }

        $payload = $request->validate([
            'status' => ['required', 'string', 'max:32'],
            'is_configured' => ['sometimes', 'boolean'],
            'auth_mode' => ['sometimes', 'string', 'max:32'],
            'client_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'refresh_token_encrypted' => ['sometimes', 'nullable', 'string'],
            'key_version' => ['sometimes', 'integer', 'min:1', 'max:9999'],
        ]);

        $config = ShopPlatformConfig::query()->firstOrNew(['shop_id' => $shopId], [
            'auth_mode' => 'oauth2',
            'key_version' => 1,
            'is_configured' => false,
            'status' => 'PENDING',
        ]);

        return $this->applyAuthUpdate($config, $payload);
    }

    private function applyAuthUpdate(ShopPlatformConfig $config, array $payload)
    {
        $status = strtoupper((string) $payload['status']);
        if (! in_array($status, $this->allowedStatus, true)) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'unsupported status, allowed: '.implode(', ', $this->allowedStatus),
                422
            );
        }

        $config->status = $status;
        if (array_key_exists('auth_mode', $payload)) {
            $config->auth_mode = $payload['auth_mode'];
        }
        if (array_key_exists('client_id', $payload)) {
            $config->client_id = $payload['client_id'];
        }
        if (array_key_exists('refresh_token_encrypted', $payload)) {
            $config->refresh_token_encrypted = $payload['refresh_token_encrypted'];
        }
        if (array_key_exists('key_version', $payload)) {
            $config->key_version = $payload['key_version'];
        }

        if (array_key_exists('is_configured', $payload)) {
            $config->is_configured = (bool) $payload['is_configured'];
        } elseif ($status === 'AUTHORIZED') {
            $config->is_configured = true;
        } elseif (in_array($status, ['EXPIRED', 'REVOKED'], true)) {
            $config->is_configured = false;
        }

        $config->save();

        DB::table('audit_logs')->insert([
            'user_id' => null,
            'action' => 'channel_account_auth_status_updated',
            'biz_type' => 'channel_account',
            'biz_id' => (string) $config->id,
            'request_id' => null,
            'ip' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 255),
            'detail_json' => json_encode([
                'shop_id' => $config->shop_id,
                'status' => $config->status,
                'is_configured' => $config->is_configured,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);

        return ApiResponse::success($config);
    }
}
