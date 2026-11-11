<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Declares its own constructor, so `new Draft()` is not what `make()` runs and the call is not rewritten.
 */
class Draft extends Model
{
    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
    }
}
