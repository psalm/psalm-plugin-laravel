<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\DirectCast;

use Illuminate\Database\Eloquent\Model;

final class DirectCastModel extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'price' => DeprecatedDirectCaster::class,
        'note' => DeprecatedDirectInboundCaster::class,
        'status' => 'enum:' . DeprecatedDirectStatus::class,
    ];
}
