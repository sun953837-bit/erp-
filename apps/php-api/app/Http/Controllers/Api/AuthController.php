<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsCodeRecord;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function sendSmsCode(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'purpose' => ['required', 'string', 'max:64'],
        ]);

        $plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $salt = Str::random(16);
        $codeHash = hash('sha256', $plainCode.$salt);
        $expiresAt = Carbon::now()->addMinutes(10);

        SmsCodeRecord::query()->create([
            'phone' => $data['phone'],
            'purpose' => $data['purpose'],
            'code_hash' => $codeHash,
            'salt' => $salt,
            'expires_at' => $expiresAt,
            'used_at' => null,
            'ip' => $request->ip(),
            'user_agent' => Str::limit($request->userAgent() ?? '', 255, ''),
            'send_status' => 'SENT',
            'created_at' => Carbon::now(),
        ]);

        $response = [
            'phone' => $data['phone'],
            'purpose' => $data['purpose'],
            'expires_at' => $expiresAt->toDateTimeString(),
        ];

        if (! app()->environment('production')) {
            $response['mock_code'] = $plainCode;
        }

        return ApiResponse::success($response);
    }

    public function verifySms(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'purpose' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $record = SmsCodeRecord::query()
            ->where('phone', $data['phone'])
            ->where('purpose', $data['purpose'])
            ->where('send_status', 'SENT')
            ->whereNull('used_at')
            ->orderByDesc('id')
            ->first();

        if (! $record) {
            return ApiResponse::error('NOT_FOUND', 'sms code record not found', 404);
        }

        if (Carbon::parse($record->expires_at)->lt(Carbon::now())) {
            return ApiResponse::error('CONFLICT', 'sms code expired', 409);
        }

        $actualHash = hash('sha256', $data['code'].$record->salt);
        if (! hash_equals($record->code_hash, $actualHash)) {
            return ApiResponse::error('UNAUTHORIZED', 'sms code invalid', 401);
        }

        $record->used_at = Carbon::now();
        $record->save();

        return ApiResponse::success([
            'verified' => true,
            'phone' => $data['phone'],
            'purpose' => $data['purpose'],
        ]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        $user = User::query()->where('username', $data['username'])->first();
        if (! $user) {
            return ApiResponse::error('UNAUTHORIZED', 'invalid credentials', 401);
        }

        if ($user->locked_until && Carbon::parse($user->locked_until)->gt(Carbon::now())) {
            return ApiResponse::error('UNAUTHORIZED', 'account locked temporarily', 401);
        }

        if (! Hash::check($data['password'], $user->password_hash)) {
            $user->failed_login_attempts = ((int) $user->failed_login_attempts) + 1;
            if ($user->failed_login_attempts >= 5) {
                $user->locked_until = Carbon::now()->addMinutes(15);
            }
            $user->save();

            return ApiResponse::error('UNAUTHORIZED', 'invalid credentials', 401);
        }

        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->last_login_at = Carbon::now();
        $user->save();

        $token = base64_encode(Str::uuid()->toString().'|'.$user->id.'|'.Str::random(24));

        return ApiResponse::success([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'status' => $user->status,
            ],
        ]);
    }
}
