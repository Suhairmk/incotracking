<?php

namespace App\Services;

use App\Jobs\SendPushNotification;
use App\Models\NotificationMessage;
use App\Models\User;

class NotificationService
{
    public function notifyUser(
        User $user,
        string $title,
        string $body,
        array $data = [],
    ): NotificationMessage {
        $message = NotificationMessage::create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'data' => $data ?: null,
            'status' => 'pending',
        ]);

        SendPushNotification::dispatch($message->id);

        return $message;
    }

    public function notifyAdmins(string $title, string $body, array $data = []): int
    {
        $admins = User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->get(['id']);

        foreach ($admins as $admin) {
            $this->notifyUser($admin, $title, $body, $data);
        }

        return $admins->count();
    }
}
