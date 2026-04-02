<?php

namespace App\Services\SyncTask;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SyncTaskRunDispatcher
{
    public function triggerWorker(): array
    {
        $enabled = (bool) config('sync.manual_run.trigger_enabled', true);
        if (! $enabled) {
            return [
                'triggered' => false,
                'success' => false,
                'reason' => 'trigger_disabled',
            ];
        }

        $url = (string) config('sync.manual_run.trigger_url', 'http://localhost:8100/internal/trigger/worker');
        $timeout = (float) config('sync.manual_run.trigger_timeout_seconds', 2.0);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->post($url);

            return $this->formatResponse($url, $response);
        } catch (\Throwable $e) {
            return [
                'triggered' => true,
                'success' => false,
                'reason' => 'http_exception',
                'url' => $url,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function failureMode(): string
    {
        $mode = (string) config('sync.manual_run.dispatch_failure_mode', 'mark_manual_review');
        if (! in_array($mode, ['mark_manual_review', 'keep_queued'], true)) {
            return 'mark_manual_review';
        }

        return $mode;
    }

    public function failureMessage(array $dispatchResult): string
    {
        if ((bool) ($dispatchResult['success'] ?? false)) {
            return 'worker dispatch succeeded';
        }

        if (($dispatchResult['reason'] ?? null) === 'trigger_disabled') {
            return 'manual run trigger is disabled';
        }

        if (! empty($dispatchResult['error'])) {
            return (string) $dispatchResult['error'];
        }

        if (! empty($dispatchResult['http_status'])) {
            return 'worker trigger returned HTTP '.(int) $dispatchResult['http_status'];
        }

        return 'worker dispatch failed';
    }

    private function formatResponse(string $url, Response $response): array
    {
        $payload = $response->json();
        if (! is_array($payload)) {
            $payload = [
                'raw' => $response->body(),
            ];
        }

        return [
            'triggered' => true,
            'success' => $response->successful(),
            'reason' => $response->successful() ? 'ok' : 'http_error',
            'http_status' => $response->status(),
            'url' => $url,
            'response' => $payload,
        ];
    }
}
