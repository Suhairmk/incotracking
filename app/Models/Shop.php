<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shop extends Model
{
    use HasFactory;

    public const TYPE_SHOP = 'shop';

    public const TYPE_DEALER = 'dealer';

    protected $fillable = [
        'created_by',
        'name',
        'type',
        'location_name',
        'address',
        'phone',
        'notes',
        'latitude_e7',
        'longitude_e7',
        'accuracy_meters',
    ];

    protected function casts(): array
    {
        return [
            'latitude_e7' => 'integer',
            'longitude_e7' => 'integer',
            'accuracy_meters' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
