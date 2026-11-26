<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A field executive's sales target for one calendar month. Achievement is
 * measured against delivered orders at their taxable value (ex-GST), so a
 * target and the sales counted towards it are always comparable.
 */
class MonthlySalesTarget extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'month',
        'target_paise',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'target_paise' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The month column always holds the first day, but callers may pass
     * any date inside the month.
     */
    public static function normalizeMonth(string $month): string
    {
        return CarbonImmutable::parse($month)->startOfMonth()->toDateString();
    }
}
