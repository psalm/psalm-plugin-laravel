<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Casts;

use Illuminate\Database\Eloquent\Model;

/** CastResolver at registry warm-up: each cast target checks its class without loading it. */
final class CastsModel extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        // Named directly: enum check, then caster contract checks.
        'status' => 'enum:' . DeprecatedDirectStatus::class,
        'price' => DeprecatedDirectCaster::class,
        // Castable: castUsing() returns a caster object, or a caster class-string.
        'castable_object' => PriceCastable::class,
        'castable_class_string' => ClassStringCastable::class,
    ];
}
