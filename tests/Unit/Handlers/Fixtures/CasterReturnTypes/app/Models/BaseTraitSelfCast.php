<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/** Imports the trait itself; a child caster only inherits `get()`. */
class BaseTraitSelfCast implements CastsAttributes
{
    use ReturnsSelfCasterTrait;
}
