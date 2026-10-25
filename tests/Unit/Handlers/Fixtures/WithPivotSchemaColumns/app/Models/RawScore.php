<?php

declare(strict_types=1);

namespace WithPivotFixture\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/** Changes the raw values `fromRawAttributes()` stores, so its cast on a column its own table lacks proves nothing. */
class RawScore extends Pivot
{
    protected $table = 'scores';

    protected $casts = ['rank' => 'string'];

    #[\Override]
    public function setRawAttributes(array $attributes, $sync = false)
    {
        return parent::setRawAttributes($attributes, $sync);
    }
}
