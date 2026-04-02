<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncTask;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    public function run(int $id)
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

        $task->next_retry_at = Carbon::now();
        $task->finished_at = null;
        $task->last_error_code = null;
        $task->last_error_message = null;
        $task->updated_by = 'manual-run-api';
        $task->updated_at = Carbon::now();
        $task->save();

        return ApiResponse::success($task, 'task queued for immediate execution');
    }
}
