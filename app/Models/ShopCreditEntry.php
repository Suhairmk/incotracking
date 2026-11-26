<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShopCreditEntry extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const TYPE_OPENING = 'opening';

    public const TYPE_SALE = 'sale';

    public const TYPE_SALES_RETURN = 'sales_return';

    public const TYPE_RECEIPT = 'receipt';

    public const TYPE_PAYMENT = 'payment';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_ORDER = 'order';

    public const SOURCE_IMPORT = 'import';

    protected $fillable = [
        'shop_id',
        'type',
        'amount_paise',
        'entry_date',
        'reference',
        'details',
        'order_id',
        'source',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_paise' => 'integer',
            'entry_date' => 'date',
        ];
    }

    /**
     * The shop's cached balance follows every ledger write — inserts, edits,
     * soft deletes and restores — so no caller (order flow, manual UI, Excel
     * import) can leave `shops.credit_balance_paise` behind.
     */
    protected static function booted(): void
    {
        $refresh = static function (ShopCreditEntry $entry): void {
            static::refreshBalance($entry->shop_id);
        };

        static::created($refresh);
        static::updated($refresh);
        static::deleted($refresh);
        static::restored($refresh);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether the entry raises the outstanding balance: sales and opening
     * balances do, receipts and payments clear it.
     */
    public function isDebit(): bool
    {
        return in_array($this->type, [self::TYPE_OPENING, self::TYPE_SALE], true);
    }

    public function isSystemGenerated(): bool
    {
        return $this->source === self::SOURCE_ORDER;
    }

    /**
     * Every human-readable label for the ledger's `type` column.
     *
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_OPENING => 'Opening balance',
            self::TYPE_SALE => 'Sale',
            self::TYPE_SALES_RETURN => 'Sales return',
            self::TYPE_RECEIPT => 'Receipt',
            self::TYPE_PAYMENT => 'Payment',
            self::TYPE_ADJUSTMENT => 'Adjustment',
        ];
    }

    /**
     * Recompute the shop's cached outstanding balance from its live rows.
     * Called after every write so the directory and reports stay consistent
     * even when entries are edited, deleted or reversed.
     */
    public static function refreshBalance(int $shopId): void
    {
        $balance = self::query()
            ->where('shop_id', $shopId)
            ->sum('amount_paise');

        Shop::query()->whereKey($shopId)->update([
            'credit_balance_paise' => (int) $balance,
        ]);
    }
}
