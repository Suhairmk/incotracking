<?php

namespace App\Services\Notifications;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FcmClient
{
    /**
     * @param  array<int, DeviceToken>  $tokens
     * @return array{sent: int, failed: int, invalid: array<int, int>}
     */
    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        if (! config('firebase.enabled')) {
            return ['sent' => 0, 'failed' => 0, 'invalid' => []];
        }

        $projectId = (string) config('firebase.project_id');

        if ($projectId === '') {
            throw new RuntimeException('FIREBASE_PROJECT_ID is required when Firebase is enabled.');
        }

        $accessToken = $this->accessToken();
        $sent = 0;
        $failed = 0;
        $invalid = [];

        foreach ($tokens as $token) {
            $payload = [
                'message' => [
                    'token' => $token->fcm_token,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'data' => array_map('strval', $data),
                    'android' => [
                        'priority' => 'high',
                        'notification' => [
                            'channel_id' => 'field_tracking',
                            'sound' => 'default',
                        ],
                    ],
                    'apns' => [
                        'payload' => [
                            'aps' => ['sound' => 'default'],
                        ],
                    ],
                ],
            ];

            $response = Http::timeout(12)
                ->withToken($accessToken)
                ->acceptJson()
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", $payload);

            if ($response->successful()) {
                $sent++;

                continue;
            }

            $failed++;
            $status = (string) $response->json('error.status');
            $errorCode = (string) ($response->json('error.details.0.errorCode') ?? '');

            if ($response->status() === 404 || $errorCode === 'UNREGISTERED') {
                $invalid[] = $token->id;
                $token->forceFill(['is_active' => false])->save();
            }

            report(new RuntimeException(
                "FCM send failed ({$response->status()} {$status} {$errorCode}) for device token {$token->id}."
            ));
        }

        return ['sent' => $sent, 'failed' => $failed, 'invalid' => $invalid];
    }

    private function accessToken(): string
    {
        $cacheKey = 'firebase:access_token:'.sha1((string) config('firebase.project_id'));

        return Cache::remember($cacheKey, 3000, function () {
            $credentials = $this->credentials();
            $now = time();
            $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $this->base64Url(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
            $payload = "{$header}.{$claims}";
            $signature = '';

            if (! openssl_sign($payload, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Unable to sign the Firebase service-account assertion.');
            }

            $assertion = $payload.'.'.$this->base64Url($signature);
            $tokenUrl = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';

            $response = Http::asForm()->timeout(12)->post($tokenUrl, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

            if (! $response->successful() || ! $response->json('access_token')) {
                throw new RuntimeException('Unable to obtain a Firebase access token.', $response->status());
            }

            return (string) $response->json('access_token');
        });
    }

    private function credentials(): array
    {
        $configured = (string) config('firebase.credentials');

        if ($configured === '') {
            throw new RuntimeException('FIREBASE_CREDENTIALS is required when Firebase is enabled.');
        }

        $json = file_exists($configured)
            ? (string) file_get_contents($configured)
            : $configured;

        $decoded = json_decode($json, true);

        if (! is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            throw new RuntimeException('FIREBASE_CREDENTIALS must be a service-account JSON file or JSON string.');
        }

        return $decoded;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
