<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single row holding the company details that head every exported
 * report and PDF. The row is created on first read, so a fresh install
 * already answers with something printable.
 */
class CompanySetting extends Model
{
    protected $fillable = [
        'name',
        'address',
        'phone',
        'email',
        'gstin',
    ];

    /**
     * The one row this table holds, created with the application name the
     * first time anybody asks for it.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'name' => (string) config('app.name', 'FieldTrack'),
        ]);
    }
}
