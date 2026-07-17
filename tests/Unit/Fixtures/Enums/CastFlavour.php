<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Enums;

/**
 * Stands in as a non-Stringable object inside a `$casts` declaration
 * ({@see \Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\ObjectCastOverriddenByCastsMethodModel}).
 *
 * An enum case is the ONLY object a property default can hold — `new` is rejected in a property initializer —
 * and PHP forbids `__toString` on an enum, so it can never satisfy the `Stringable` check that
 * `HasAttributes::ensureCastsAreStringValues()` applies before throwing.
 */
enum CastFlavour: string
{
    case Vanilla = 'vanilla';
}
