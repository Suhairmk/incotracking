<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'client_order_id',
        'shop_id',
        'employee_id',
        'status',
        'business_date',
        'total_paise',
        'subtotal_paise',
        'tax_paise',
        'tax_rate_bp',
        'notes',
        'device_created_at',
        'server_recorded_at',
        'delivered_at',
        'delivered_by',
        'cancelled_at',
        'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'business_date' => 'date',
            'total_paise' => 'integer',
            'subtotal_paise' => 'integer',
            'tax_paise' => 'integer',
            'tax_rate_bp' => 'integer',
            'device_created_at' => 'datetime',
            'server_recorded_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isPending(): bool
    {
        return $this->status === OrderStatus::Pending;
    }

    /**
     * Whether this order carries GST. Orders placed before the tax rollout
     * keep a zero rate and their original totals for ever.
     */
    public function isTaxed(): bool
    {
        return ($this->tax_rate_bp ?? 0) > 0;
    }
}
