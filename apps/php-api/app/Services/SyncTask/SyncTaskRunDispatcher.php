<?php

namespace App\Services\SyncTask;

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

            return [
                'triggered' => true,
                'success' => $response->successful(),
                'http_status' => $response->status(),
                'url' => $url,
                'response' => $response->json(),
            ];
        } catch (\Throwable $e) {
            return [
                'triggered' => true,
                'success' => false,
                'url' => $url,
                'error' => $e->getMessage(),
            ];
        }
    }
}
