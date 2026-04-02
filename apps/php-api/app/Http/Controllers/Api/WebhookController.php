<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Support\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class WebhookController extends Controller
{
    private array $allowedPlatforms = [
        'xianyu',
        'zbj',
        'amazon',
        'tiktok',
        'japan',
        'korea',
    ];

    public function receive(Request $request, string $platform)
    {
        $platformCode = strtolower($platform);
        if (! in_array($platformCode, $this->allowedPlatforms, true)) {
            return ApiResponse::error('VALIDATION_ERROR', 'unsupported platform', 422);
        }

        $rawBody = $request->getContent();
        if (! is_string($rawBody) || trim($rawBody) === '') {
            return ApiResponse::error('VALIDATION_ERROR', 'empty webhook payload', 400);
        }

        $signature = trim((string) $request->header('X-Signature', ''));
        if ($signature === '') {
            return ApiResponse::error('UNAUTHORIZED', 'missing signature', 401);
        }

        $secret = $this->resolveWebhookSecret($platformCode);
        if ($secret === '') {
            return ApiResponse::error('SERVER_MISCONFIG', 'webhook secret is not configured', 500);
        }

        $expectedSignature = hash_hmac('sha256', $rawBody, $secret);
        if (! hash_equals($expectedSignature, $signature)) {
            $this->writeAuditLog(
                action: 'webhook_signature_rejected',
                platformCode: $platformCode,
                bizId: null,
                detail: ['reason' => 'signature_mismatch']
            );
            return ApiResponse::error('UNAUTHORIZED', 'signature validation failed', 401);
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return ApiResponse::error('VALIDATION_ERROR', 'payload must be valid json object', 400);
        }

        $eventKey = $this->resolveEventKey($request, $payload, $platformCode, $rawBody);

        try {
            $result = DB::transaction(function () use ($platformCode, $eventKey, $signature, $payload) {
                $event = WebhookEvent::query()
                    ->where('platform_code', $platformCode)
                    ->where('event_key', $eventKey)
                    ->lockForUpdate()
                    ->first();

                if ($event && in_array($event->status, ['PROCESSED', 'IGNORED'], true)) {
                    return [
                        'deduplicated' => true,
                        'event' => $event,
                    ];
                }

                if (! $event) {
                    $event = new WebhookEvent();
                    $event->platform_code = $platformCode;
                    $event->event_key = $eventKey;
                    $event->attempts = 0;
                }

                $event->signature = $signature;
                $event->payload_json = $payload;
                $event->attempts = ((int) $event->attempts) + 1;
                $event->status = 'RECEIVED';
                $event->error_message = null;
                $event->save();

                try {
                    $status = $this->processEvent($payload);
                    $event->status = $status;
                    $event->processed_at = now();
                    $event->save();

                    $this->writeAuditLog(
                        action: 'webhook_processed',
                        platformCode: $platformCode,
                        bizId: (string) $event->id,
                        detail: [
                            'event_key' => $event->event_key,
                            'status' => $event->status,
                            'attempts' => $event->attempts,
                        ]
                    );

                    return [
                        'deduplicated' => false,
                        'event' => $event,
                        'failed' => false,
                    ];
                } catch (Throwable $e) {
                    $event->status = 'FAILED';
                    $event->error_message = substr($e->getMessage(), 0, 1000);
                    $event->save();

                    $this->writeAuditLog(
                        action: 'webhook_failed',
                        platformCode: $platformCode,
                        bizId: (string) $event->id,
                        detail: [
                            'event_key' => $event->event_key,
                            'attempts' => $event->attempts,
                            'error' => $event->error_message,
                        ]
                    );

                    return [
                        'deduplicated' => false,
                        'event' => $event,
                        'failed' => true,
                        'error_message' => $event->error_message,
                    ];
                }
            });
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'duplicate')) {
                $existing = WebhookEvent::query()
                    ->where('platform_code', $platformCode)
                    ->where('event_key', $eventKey)
                    ->first();
                if ($existing) {
                    return ApiResponse::success([
                        'event_id' => $existing->id,
                        'event_key' => $existing->event_key,
                        'status' => $existing->status,
                        'deduplicated' => true,
                    ], 'duplicate webhook ignored');
                }
            }
            return ApiResponse::error('DB_ERROR', 'webhook persistence failed', 500);
        } catch (Throwable $e) { // unexpected transaction failure
            return ApiResponse::error('PROCESSING_ERROR', $e->getMessage(), 500);
        }

        /** @var WebhookEvent $event */
        $event = $result['event'];
        if ((bool) ($result['failed'] ?? false)) {
            return ApiResponse::error(
                'PROCESSING_ERROR',
                (string) ($result['error_message'] ?? 'webhook processing failed'),
                500,
                [
                    'event_id' => $event->id,
                    'event_key' => $event->event_key,
                    'status' => $event->status,
                    'attempts' => $event->attempts,
                ]
            );
        }
        return ApiResponse::success([
            'event_id' => $event->id,
            'event_key' => $event->event_key,
            'status' => $event->status,
            'attempts' => $event->attempts,
            'deduplicated' => (bool) $result['deduplicated'],
        ]);
    }

    public function index(Request $request)
    {
        $platformCode = strtolower($request->string('platform_code')->toString());
        $status = strtoupper($request->string('status')->toString());
        $limit = max(1, min(200, (int) $request->integer('limit', 50)));

        $query = WebhookEvent::query()->orderByDesc('id');
        if ($platformCode !== '') {
            $query->where('platform_code', $platformCode);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }

        return ApiResponse::success($query->limit($limit)->get());
    }

    public function retry(int $id)
    {
        $event = WebhookEvent::query()->find($id);
        if (! $event) {
            return ApiResponse::error('NOT_FOUND', 'webhook event not found', 404);
        }

        if (! in_array($event->status, ['FAILED', 'RECEIVED'], true)) {
            return ApiResponse::error('CONFLICT', 'webhook event status is not retryable', 409);
        }

        try {
            $event->attempts = ((int) $event->attempts) + 1;
            $event->status = 'RECEIVED';
            $event->error_message = null;
            $event->save();

            $payload = is_array($event->payload_json) ? $event->payload_json : [];
            $status = $this->processEvent($payload);
            $event->status = $status;
            $event->processed_at = now();
            $event->save();

            $this->writeAuditLog(
                action: 'webhook_retry_processed',
                platformCode: (string) $event->platform_code,
                bizId: (string) $event->id,
                detail: [
                    'event_key' => $event->event_key,
                    'status' => $event->status,
                    'attempts' => $event->attempts,
                ]
            );

            return ApiResponse::success($event, 'webhook event retried');
        } catch (Throwable $e) {
            $event->status = 'FAILED';
            $event->error_message = substr($e->getMessage(), 0, 1000);
            $event->save();

            $this->writeAuditLog(
                action: 'webhook_retry_failed',
                platformCode: (string) $event->platform_code,
                bizId: (string) $event->id,
                detail: [
                    'event_key' => $event->event_key,
                    'attempts' => $event->attempts,
                    'error' => $event->error_message,
                ]
            );

            return ApiResponse::error('PROCESSING_ERROR', $e->getMessage(), 500);
        }
    }

    private function processEvent(array $payload): string
    {
        $eventType = strtolower((string) ($payload['event_type'] ?? ''));
        if ($eventType === 'ping') {
            return 'IGNORED';
        }
        if (! empty($payload['simulate_fail'])) {
            throw new \RuntimeException('simulated processing failure');
        }
        return 'PROCESSED';
    }

    private function resolveWebhookSecret(string $platformCode): string
    {
        $platformKey = 'WEBHOOK_SECRET_'.strtoupper($platformCode);
        $platformSecret = trim((string) env($platformKey, ''));
        if ($platformSecret !== '') {
            return $platformSecret;
        }
        return trim((string) env('WEBHOOK_SHARED_SECRET', ''));
    }

    private function resolveEventKey(
        Request $request,
        array $payload,
        string $platformCode,
        string $rawBody
    ): string {
        $headerKey = trim((string) $request->header('X-Event-Id', ''));
        if ($headerKey !== '') {
            return $headerKey;
        }

        foreach (['event_id', 'idempotency_key', 'message_id', 'request_id'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return hash('sha256', $platformCode.'|'.$rawBody);
    }

    private function writeAuditLog(string $action, string $platformCode, ?string $bizId, array $detail): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => null,
            'action' => $action,
            'biz_type' => 'webhook',
            'biz_id' => $bizId,
            'request_id' => null,
            'ip' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 255),
            'detail_json' => json_encode([
                'platform_code' => $platformCode,
                'detail' => $detail,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);
    }
}
