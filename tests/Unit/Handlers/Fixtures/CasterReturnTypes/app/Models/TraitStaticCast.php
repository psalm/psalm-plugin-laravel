<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class TraitStaticCast implements CastsAttributes
{
    use ReturnsStaticCasterTrait;
}
