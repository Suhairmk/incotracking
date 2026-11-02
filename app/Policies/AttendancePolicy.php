<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;

class AttendancePolicy
{
    public function view(User $user, Attendance $attendance): bool
    {
        return $user->isAdmin() || $attendance->employee_id === $user->id;
    }

    public function update(User $user, Attendance $attendance): bool
    {
        return $user->isAdmin() || $attendance->employee_id === $user->id;
    }
}
