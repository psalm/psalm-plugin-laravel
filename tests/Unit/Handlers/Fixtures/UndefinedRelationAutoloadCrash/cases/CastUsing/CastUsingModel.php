<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\CastUsing;

use Illuminate\Database\Eloquent\Model;

final class CastUsingModel extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'price' => PriceCastable::class,
        'inbound' => InboundCastable::class,
        'class_string' => ClassStringCastable::class,
        'class_string_inbound' => ClassStringInboundCastable::class,
    ];
}
