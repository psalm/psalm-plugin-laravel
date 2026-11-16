<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

final class TraitSelfCast implements CastsAttributes
{
    use ReturnsSelfCasterTrait;
}
