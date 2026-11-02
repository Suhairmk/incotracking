<?php

namespace App\Models;

use App\Enums\TrackingState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLiveLocation extends Model
{
    protected $table = 'employee_live_locations';

    protected $primaryKey = 'employee_id';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_recorded_at' => 'datetime',
            'last_received_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'updated_at' => 'datetime',
            'tracking_state' => TrackingState::class,
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
