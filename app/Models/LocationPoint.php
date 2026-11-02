<?php

namespace App\Models;

use App\Enums\LocationQuality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationPoint extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'employee_id',
        'attendance_id',
        'client_point_id',
        'latitude_e7',
        'longitude_e7',
        'accuracy_meters',
        'speed_cm_per_second',
        'heading_degrees',
        'recorded_at',
        'received_at',
        'quality_status',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'received_at' => 'datetime',
            'created_at' => 'datetime',
            'quality_status' => LocationQuality::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('quality_status', LocationQuality::Accepted->value);
    }
}
