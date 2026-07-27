<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Overrides getKey() so callers must fall back to the stub's `int|string` — the plugin
 * must not narrow past a user override of the method it is narrowing.
 */
final class GetKeyOverrideModel extends Model
{
    public function getKey()
    {
        return parent::getKey();
    }
}
