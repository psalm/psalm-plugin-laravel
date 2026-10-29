<?php

declare(strict_types=1);

namespace ModelDatesFixture\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/** A set-only `Attribute` has no getter, so Laravel still date-converts the column on read. */
class Draft extends Model
{
    /** @return Attribute<never, string> */
    protected function createdAt(): Attribute
    {
        return Attribute::set(static fn(string $value): string => $value);
    }
}
