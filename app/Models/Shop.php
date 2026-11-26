<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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
        'whatsapp_number',
        'notes',
        'gst_number',
        'credit_balance_paise',
        'is_visible_to_field',
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
            'credit_balance_paise' => 'integer',
            'is_visible_to_field' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function creditEntries(): HasMany
    {
        return $this->hasMany(ShopCreditEntry::class)->orderByDesc('entry_date')
            ->orderByDesc('id');
    }

    /**
     * The executives this shop is shared with. Administrators are not listed
     * here: they can open every shop regardless of this table.
     */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'shop_employee_access', 'shop_id', 'employee_id')
            ->withTimestamps();
    }

    /**
     * Whether this executive may work with this shop: they hold a seat on
     * the access list. The list is the single source of truth — creating a
     * shop takes a seat automatically, and only an administrator can hand
     * one over or take it away.
     */
    public function isAssignedTo(User $user): bool
    {
        return $this->staff()->whereKey($user->id)->exists();
    }

    /**
     * The author of a new shop joins its access list immediately, so a shop
     * is never orphaned: what the directory rules read is exactly what the
     * administrator sees on the access screen.
     */
    protected static function booted(): void
    {
        static::created(function (Shop $shop): void {
            if ($shop->created_by !== null) {
                $shop->staff()->attach($shop->created_by);
            }
        });
    }

    /**
     * Hand a new executive the whole visible directory in one statement, the
     * same way the migration granted it to the staff already in place. The
     * administrator can still take individual shops away afterwards.
     *
     * @param  iterable<int>  $employeeIds
     */
    public static function grantVisibleShopsTo(iterable $employeeIds): void
    {
        $now = now();
        $shopIds = DB::table('shops')->where('is_visible_to_field', true)->pluck('id');
        $rows = [];

        foreach ($employeeIds as $employeeId) {
            foreach ($shopIds as $shopId) {
                $rows[] = [
                    'shop_id' => $shopId,
                    'employee_id' => $employeeId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('shop_employee_access')->insertOrIgnore($rows);
        }
    }
}
