<?php

declare(strict_types=1);

namespace ModelDatesFixture\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/** An `Attribute` factory that throws on the constructor-less warm-up instance must not cost the model its casts. */
class Moody extends Model
{
    protected $table = 'moodies';

    protected $casts = [
        'active' => 'boolean',
    ];

    /** @return Attribute<string, never> */
    protected function createdAt(): Attribute
    {
        $zone = new \DateTimeZone((string) $this->getAttribute('timezone'));

        return Attribute::make(get: static fn(?string $value): string => $zone->getName() . (string) $value);
    }
}
