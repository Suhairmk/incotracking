<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $login = trim((string) $request->input('login'));

        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $user = User::query()->where($field, $login)->first();

        if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'login' => 'The provided credentials are incorrect.',
            ]);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'This account has been deactivated. Contact your administrator.',
            ], 403);
        }

        $user->forceFill(['last_login_at' => now('UTC')])->save();

        $tokenName = 'mobile-'.($request->input('device_name') ?: 'device');
        $token = $user->createToken($tokenName);
        $expirationMinutes = config('sanctum.expiration');

        $this->logger->log('auth.login', $user, $user, [
            'device_name' => $request->input('device_name'),
        ]);

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'token_expires_at' => $expirationMinutes
                    ? now('UTC')->addMinutes((int) $expirationMinutes)->toIso8601String()
                    : null,
                'user' => new UserResource($user),
            ],
            'message' => 'Signed in successfully.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        $token?->delete();

        return response()->json(['message' => 'Signed out successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()),
        ]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = strtolower((string) $request->input('email'));
        $user = User::query()->where('email', $email)->where('is_active', true)->first();

        if ($user) {
            try {
                Password::sendResetLink(['email' => $email]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'message' => 'If an active account exists for that email, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();

                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => __($status),
            ], 422);
        }

        return response()->json(['message' => 'Password reset successfully. Please sign in.']);
    }
}
