<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

final class Issue1944Pivot extends Pivot
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['role' => 'string'];
    }
}
