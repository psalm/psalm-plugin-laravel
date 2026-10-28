<?php

declare(strict_types=1);

namespace AutoloadCrashFixture;

use Illuminate\Database\Eloquent\Model;

final class CastModel extends Model
{
    /** @var array<string, string> */
    protected $casts = ['status' => DeprecatedStatus::class, 'price' => DeprecatedCaster::class];

    // toArray() serializes a class-cast column through its caster, so this accessor must not win.
    protected function getPriceAttribute(): string
    {
        return '';
    }
}
