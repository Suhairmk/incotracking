<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdminViewer = $request->user()?->isAdmin() ?? false;

        return [
            'id' => $this->id,
            'employee_code' => $this->employee_code,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role?->value,
            'is_active' => (bool) $this->is_active,
            'profile_photo_url' => $this->profile_photo_path
                ? route('photos.profile', $this->id, false)
                : null,
            'profile_photo_thumb_url' => $this->profile_photo_thumb_path
                ? route('photos.profile', ['user' => $this->id, 'variant' => 'thumb'], false)
                : null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'today_attendance_id' => $this->when(isset($this->today_attendance_id), fn () => $this->today_attendance_id),
            'today_status' => $this->when(isset($this->today_status), fn () => $this->today_status),
            'today_distance_meters' => $this->when(isset($this->today_distance_meters), fn () => (int) $this->today_distance_meters),
            $this->mergeWhen($isAdminViewer && isset($this->attendance_count), [
                'attendance_count' => (int) $this->attendance_count,
                // loadCount() names this "visits_count"; accept either spelling.
                'visit_count' => (int) ($this->visit_count ?? $this->visits_count ?? 0),
            ]),
        ];
    }
}
