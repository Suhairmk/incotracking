<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\DeleteDeviceTokenRequest;
use App\Http\Requests\Employee\RegisterDeviceTokenRequest;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;

class DeviceTokenController extends Controller
{
    public function store(RegisterDeviceTokenRequest $request): JsonResponse
    {
        $data = $request->validated();
        $hash = hash('sha256', $data['fcm_token']);

        DeviceToken::query()
            ->where('token_hash', $hash)
            ->where('user_id', '!=', $request->user()->id)
            ->delete();

        $token = DeviceToken::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'device_id' => $data['device_id'],
            ],
            [
                'fcm_token' => $data['fcm_token'],
                'token_hash' => $hash,
                'platform' => $data['platform'],
                'is_active' => true,
                'last_used_at' => now('UTC'),
            ]
        );

        return response()->json([
            'data' => [
                'id' => $token->id,
                'device_id' => $token->device_id,
                'platform' => $token->platform,
            ],
            'message' => 'Device registered for notifications.',
        ]);
    }

    public function destroy(DeleteDeviceTokenRequest $request): JsonResponse
    {
        DeviceToken::query()
            ->where('user_id', $request->user()->id)
            ->where('device_id', $request->validated('device_id'))
            ->delete();

        return response()->json(['message' => 'Device unregistered.']);
    }
}
