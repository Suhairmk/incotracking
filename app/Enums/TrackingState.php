<?php

namespace App\Enums;

enum TrackingState: string
{
    case Unknown = 'unknown';
    case Moving = 'moving';
    case Stationary = 'stationary';
    case Interrupted = 'interrupted';
    case Completed = 'completed';
}
