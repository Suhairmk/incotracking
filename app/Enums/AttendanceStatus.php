<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
}
