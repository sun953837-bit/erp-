<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncReceiptLog;
use App\Models\SyncTask;
use App\Support\ApiResponse;

class SyncReceiptLogController extends Controller
{
    public function indexByTask(int $id)
    {
        $task = SyncTask::query()->find($id);
        if (! $task) {
            return ApiResponse::error('NOT_FOUND', 'sync task not found', 404);
        }

        $items = SyncReceiptLog::query()
            ->where('sync_task_id', $id)
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success($items);
    }
}
