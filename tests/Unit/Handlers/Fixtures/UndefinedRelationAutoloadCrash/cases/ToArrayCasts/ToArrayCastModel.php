<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\ToArrayCasts;

use Illuminate\Database\Eloquent\Model;

final class ToArrayCastModel extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'status' => DeprecatedToArrayStatus::class,
        'price' => DeprecatedMoneyCaster::class,
    ];

    // toArray() serializes a class-cast column through its caster, so this accessor must not win.
    protected function getPriceAttribute(): string
    {
        return '';
    }
}
