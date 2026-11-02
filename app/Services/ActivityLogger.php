<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class ActivityLogger
{
    public function log(
        string $action,
        ?User $actor = null,
        ?Model $subject = null,
        array $metadata = [],
    ): void {
        try {
            ActivityLog::create([
                'user_id' => $actor?->id,
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'metadata' => $metadata ?: null,
                'ip_address' => Request::ip(),
            ]);
        } catch (\Throwable $e) {
            // Audit logging must never take down an attendance or visit request.
            report($e);
        }
    }
}
