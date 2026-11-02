<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Models\NotificationMessage;
use App\Services\Notifications\FcmClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $messageId) {}

    public function handle(FcmClient $client): void
    {
        $message = NotificationMessage::find($this->messageId);

        if (! $message) {
            return;
        }

        if (! config('firebase.enabled')) {
            $message->forceFill([
                'status' => 'skipped',
                'error' => 'Firebase is disabled.',
            ])->save();

            return;
        }

        $tokens = DeviceToken::query()
            ->where('user_id', $message->user_id)
            ->where('is_active', true)
            ->get();

        if ($tokens->isEmpty()) {
            $message->forceFill([
                'status' => 'skipped',
                'error' => 'No active device tokens.',
            ])->save();

            return;
        }

        try {
            $result = $client->send(
                $tokens->all(),
                $message->title,
                $message->body,
                $message->data ?? []
            );

            $message->forceFill([
                'status' => $result['sent'] > 0 ? 'sent' : 'failed',
                'error' => $result['failed'] > 0
                    ? "{$result['failed']} delivery attempt(s) failed."
                    : null,
                'sent_at' => $result['sent'] > 0 ? now('UTC') : null,
            ])->save();
        } catch (\Throwable $e) {
            $message->forceFill([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ])->save();

            throw $e;
        }
    }
}
