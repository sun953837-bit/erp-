<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncTask;
use App\Services\SyncTask\SyncTaskRunDispatcher;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncTaskController extends Controller
{
    private array $allowedTaskTypes = [
        'product_publish',
        'product_update',
        'inventory_sync',
        'order_pull',
        'refund_pull',
        'listing_pull',
        'service_pull',
    ];

    private array $retryableStatus = [
        'FAIL',
        'MANUAL_REVIEW',
    ];

    public function index(Request $request)
    {
        $query = SyncTask::query()->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('task_type')) {
            $query->where('task_type', $request->string('task_type')->toString());
        }

        return ApiResponse::success($query->paginate(20));
    }

    public function show(int $id)
    {
        $task = SyncTask::query()->find($id);
        if (! $task) {
            return ApiResponse::error('NOT_FOUND', 'sync task not found', 404);
        }

        return ApiResponse::success($task);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'task_type' => ['required', 'string', 'max:64'],
            'biz_type' => ['required', 'string', 'max:64'],
            'biz_id' => ['required', 'string', 'max:128'],
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'platform_code' => ['required', 'string', 'max:64'],
            'site_code' => ['required', 'string', 'max:64'],
            'payload_json' => ['required', 'array'],
            'max_retry_count' => ['nullable', 'integer', 'min:1', 'max:10'],
            'created_by' => ['nullable', 'string', 'max:64'],
        ]);

        if (! in_array($data['task_type'], $this->allowedTaskTypes, true)) {
            return ApiResponse::error('VALIDATION_ERROR', 'unsupported task_type', 422);
        }

        $taskNo = sprintf('TK%s%s', Carbon::now()->format('YmdHis'), strtoupper(Str::random(6)));
        $idempotencyKey = hash(
            'sha256',
            $data['task_type'].'|'.$data['biz_type'].'|'.$data['biz_id'].'|'.
            $data['shop_id'].'|'.$data['platform_code'].'|'.$data['site_code'].'|'.
            json_encode($data['payload_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'|'.Str::uuid()
        );

        $task = SyncTask::query()->create([
            'task_no' => $taskNo,
            'task_type' => $data['task_type'],
            'biz_type' => $data['biz_type'],
            'biz_id' => $data['biz_id'],
            'shop_id' => $data['shop_id'],
            'platform_code' => $data['platform_code'],
            'site_code' => $data['site_code'],
            'status' => 'PENDING',
            'idempotency_key' => $idempotencyKey,
            'payload_json' => $data['payload_json'],
            'result_summary_json' => null,
            'retry_count' => 0,
            'max_retry_count' => $data['max_retry_count'] ?? 3,
            'last_error_code' => null,
            'last_error_message' => null,
            'next_retry_at' => null,
            'accepted_at' => null,
            'finished_at' => null,
            'created_by' => $data['created_by'] ?? null,
            'updated_by' => $data['created_by'] ?? null,
        ]);

        return ApiResponse::success($task, 'success', 'OK', 201);
    }

    public function retry(int $id)
    {
        $task = SyncTask::query()->find($id);
        if (! $task) {
            return ApiResponse::error('NOT_FOUND', 'sync task not found', 404);
        }

        if (! in_array($task->status, $this->retryableStatus, true)) {
            return ApiResponse::error('CONFLICT', 'task status is not retryable', 409);
        }

        if ($task->retry_count >= $task->max_retry_count) {
            $task->status = 'MANUAL_REVIEW';
            $task->updated_at = Carbon::now();
            $task->save();

            return ApiResponse::error('CONFLICT', 'max retry count reached', 409);
        }

        $task->status = 'RETRYING';
        $task->next_retry_at = Carbon::now();
        $task->last_error_code = null;
        $task->last_error_message = null;
        $task->updated_at = Carbon::now();
        $task->save();

        return ApiResponse::success($task);
    }

    public function run(int $id, SyncTaskRunDispatcher $dispatcher)
    {
        $task = SyncTask::query()->find($id);
        if (! $task) {
            return ApiResponse::error('NOT_FOUND', 'sync task not found', 404);
        }

        $blockedStatus = ['RUNNING', 'SUCCESS', 'CANCELLED', 'ACCEPTED'];
        if (in_array($task->status, $blockedStatus, true)) {
            return ApiResponse::error('CONFLICT', 'task status is not runnable by manual run', 409);
        }

        if (in_array($task->status, ['FAIL', 'MANUAL_REVIEW'], true)) {
            $task->status = 'RETRYING';
        }

        $manualRunAt = Carbon::now();
        $task->next_retry_at = $manualRunAt;
        $task->finished_at = null;
        $task->last_error_code = null;
        $task->last_error_message = null;
        $task->updated_by = 'manual-run-api';
        $task->updated_at = $manualRunAt;
        $task->save();

        $dispatchResult = $dispatcher->triggerWorker();
        $dispatchMode = $dispatcher->failureMode();
        $this->writeManualRunAuditLog($task, $dispatchResult, $dispatchMode, $manualRunAt);

        if (! (bool) ($dispatchResult['success'] ?? false)) {
            $task->last_error_code = 'MANUAL_DISPATCH_FAILED';
            $task->last_error_message = Str::limit($dispatcher->failureMessage($dispatchResult), 1000, '');
            $task->updated_at = Carbon::now();

            if ($dispatchMode === 'mark_manual_review') {
                $task->status = 'MANUAL_REVIEW';
                $task->next_retry_at = null;
                $task->save();

                return ApiResponse::error(
                    'SYNC_WORKER_UNAVAILABLE',
                    'manual run dispatch failed, task moved to MANUAL_REVIEW',
                    503,
                    [
                        'task' => $task,
                        'dispatch' => $dispatchResult,
                        'dispatch_failure_mode' => $dispatchMode,
                    ]
                );
            }

            $task->save();

            return ApiResponse::success([
                'task' => $task,
                'dispatch' => $dispatchResult,
                'dispatch_failure_mode' => $dispatchMode,
            ], 'task queued, but worker dispatch failed', 'PARTIAL_OK', 202);
        }

        return ApiResponse::success([
            'task' => $task,
            'dispatch' => $dispatchResult,
            'dispatch_failure_mode' => $dispatchMode,
        ], 'task queued for immediate execution');
    }

    private function writeManualRunAuditLog(
        SyncTask $task,
        array $dispatchResult,
        string $dispatchMode,
        Carbon $manualRunAt
    ): void {
        try {
            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => (bool) ($dispatchResult['success'] ?? false)
                    ? 'sync_task_manual_run_dispatched'
                    : 'sync_task_manual_run_dispatch_failed',
                'biz_type' => 'sync_task',
                'biz_id' => (string) $task->id,
                'request_id' => null,
                'ip' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 255),
                'detail_json' => json_encode([
                    'task_no' => $task->task_no,
                    'task_status' => $task->status,
                    'manual_run_at' => $manualRunAt->toDateTimeString(),
                    'dispatch_failure_mode' => $dispatchMode,
                    'dispatch' => $dispatchResult,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // keep manual-run api non-blocking even if audit persistence fails
        }
    }
}
