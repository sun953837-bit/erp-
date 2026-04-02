<?php

namespace Tests\Feature;

use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebhookReplayCompensationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('webhook.require_timestamp', true);
        config()->set('webhook.allowed_drift_seconds', 300);
    }

    public function test_processed_event_replay_is_deduplicated(): void
    {
        $payload = [
            'event_id' => 'evt-replay-001',
            'event_type' => 'order_update',
            'order_id' => 'ORD-1001',
        ];
        $firstResponse = $this->postWebhook('xianyu', $payload, 'evt-replay-001');
        $firstResponse->assertStatus(200);
        $firstResponse->assertJsonPath('success', true);
        $firstResponse->assertJsonPath('data.deduplicated', false);

        $secondResponse = $this->postWebhook('xianyu', $payload, 'evt-replay-001');
        $secondResponse->assertStatus(200);
        $secondResponse->assertJsonPath('success', true);
        $secondResponse->assertJsonPath('data.deduplicated', true);

        $event = WebhookEvent::query()->where('platform_code', 'xianyu')->where('event_key', 'evt-replay-001')->first();
        $this->assertNotNull($event);
        $this->assertSame('PROCESSED', $event->status);
        $this->assertSame(1, (int) $event->attempts);
    }

    public function test_failed_event_can_be_compensated_and_retried(): void
    {
        $payload = [
            'event_id' => 'evt-compensate-001',
            'event_type' => 'order_update',
            'simulate_fail' => true,
        ];
        $failedResponse = $this->postWebhook('zbj', $payload, 'evt-compensate-001');
        $failedResponse->assertStatus(500);
        $failedResponse->assertJsonPath('code', 'PROCESSING_ERROR');

        $event = WebhookEvent::query()->where('platform_code', 'zbj')->where('event_key', 'evt-compensate-001')->first();
        $this->assertNotNull($event);
        $this->assertSame('FAILED', $event->status);
        $this->assertSame(1, (int) $event->attempts);

        $compensatedPayload = Arr::except((array) $event->payload_json, ['simulate_fail']);
        DB::table('webhook_events')
            ->where('id', $event->id)
            ->update([
                'payload_json' => json_encode($compensatedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);

        $retryResponse = $this->postJson("/api/webhooks/events/{$event->id}/retry", []);
        $retryResponse->assertStatus(200);
        $retryResponse->assertJsonPath('success', true);
        $retryResponse->assertJsonPath('data.status', 'PROCESSED');
        $retryResponse->assertJsonPath('data.attempts', 2);

        $event->refresh();
        $this->assertSame('PROCESSED', $event->status);
        $this->assertSame(2, (int) $event->attempts);
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        $payload = [
            'event_id' => 'evt-stale-001',
            'event_type' => 'order_update',
        ];
        $oldTs = now()->subHour()->timestamp;
        $response = $this->postWebhook('xianyu', $payload, 'evt-stale-001', $oldTs);
        $response->assertStatus(401);
        $response->assertJsonPath('code', 'UNAUTHORIZED');
    }

    private function postWebhook(string $platform, array $payload, string $eventId, ?int $timestamp = null)
    {
        $requestTimestamp = $timestamp ?? now()->timestamp;
        $payload['timestamp'] = $requestTimestamp;
        $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $secret = (string) env('WEBHOOK_SHARED_SECRET', 'stage1-test-secret');
        $signature = hash_hmac('sha256', (string) $rawBody, $secret);

        return $this->call(
            'POST',
            "/api/webhooks/{$platform}/events",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $signature,
                'HTTP_X_EVENT_ID' => $eventId,
                'HTTP_X_TIMESTAMP' => (string) $requestTimestamp,
            ],
            (string) $rawBody
        );
    }
}
