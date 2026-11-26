<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'unit',
        'unit_price_paise',
        'quantity',
        'line_total_paise',
        'line_tax_paise',
        'tax_rate_bp',
    ];

    protected function casts(): array
    {
        return [
            'unit_price_paise' => 'integer',
            'quantity' => 'integer',
            'line_total_paise' => 'integer',
            'line_tax_paise' => 'integer',
            'tax_rate_bp' => 'integer',
        ];
    }

    /**
     * What the customer pays for this line: taxable value plus its GST.
     * For pre-GST orders the tax is zero, so this equals the line total.
     */
    public function grossTotalPaise(): int
    {
        return $this->line_total_paise + ($this->line_tax_paise ?? 0);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
