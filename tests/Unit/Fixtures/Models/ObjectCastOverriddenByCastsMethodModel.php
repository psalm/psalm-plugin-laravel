<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Enums\CastFlavour;

/**
 * Declares an OBJECT cast that `casts()` then overrides — the one shape where warm-up's normalizer can throw
 * for a model Laravel constructs perfectly well.
 *
 * Laravel normalizes `array_merge($this->casts, $this->casts())` and `casts()` WINS on collisions, so the enum
 * below never reaches `ensureCastsAreStringValues()`'s `is_object` arm at construction. Warm-up sees the
 * declared half ALONE, so it would throw (12.26+, where that arm exists) and take all four instance-derived
 * sections with it — while `new` on this model returns fine. Hence the mirror's catch.
 *
 * An enum case is the only object a `$casts` property default can hold (`new` is rejected in a property
 * initializer) and an enum cannot be Stringable (PHP forbids `__toString` on enums), so that arm is only ever
 * reachable here as a throw.
 *
 * @internal fixture used by ModelInstancePreparerTest and ModelMetadataRegistryTest
 */
final class ObjectCastOverriddenByCastsMethodModel extends Model
{
    /** @var array<string, string|CastFlavour> */
    protected $casts = [
        'flavour' => CastFlavour::Vanilla,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['flavour' => 'string'];
    }
}
