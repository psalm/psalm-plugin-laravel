<?php

declare(strict_types=1);

namespace ModelDatesFixture\Models;

use Illuminate\Database\Eloquent\Model;

/** An accessor on a timestamp column: Laravel calls it with the raw value before any date conversion, so no date cast is modeled. */
class Journal extends Model
{
    public function getCreatedAtAttribute(?string $value): string
    {
        return (string) $value;
    }
}
