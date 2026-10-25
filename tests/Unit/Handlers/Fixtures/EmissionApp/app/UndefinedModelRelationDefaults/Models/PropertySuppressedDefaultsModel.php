<?php

declare(strict_types=1);

namespace RelationDefaultsFixture\Models;

use Illuminate\Database\Eloquent\Model;

final class PropertySuppressedDefaultsModel extends Model
{
    /** @psalm-suppress UndefinedModelRelation Runtime relation registration. */
    protected $with = ['suppressedMissing'];
}
