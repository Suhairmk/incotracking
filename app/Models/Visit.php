<?php

namespace App\Models;

use App\Enums\VisitOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Visit extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'attendance_id',
        'client_visit_id',
        'customer_name',
        'contact_person',
        'contact_number',
        'photo_path',
        'photo_thumb_path',
        'latitude_e7',
        'longitude_e7',
        'accuracy_meters',
        'device_recorded_at',
        'server_recorded_at',
        'remarks',
        'outcome',
    ];

    protected function casts(): array
    {
        return [
            'device_recorded_at' => 'datetime',
            'server_recorded_at' => 'datetime',
            'outcome' => VisitOutcome::class,
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
}
