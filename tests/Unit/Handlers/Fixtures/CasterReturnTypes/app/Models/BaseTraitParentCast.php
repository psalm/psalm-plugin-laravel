<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class BaseTraitParentCast extends LegacyBase implements CastsAttributes
{
    use ReturnsParentCasterTrait;
}
