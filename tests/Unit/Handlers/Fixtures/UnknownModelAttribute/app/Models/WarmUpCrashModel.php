<?php

declare(strict_types=1);

namespace KnownAttributeFixture\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Deliberately fails the registry's schema section through `getTable()`. The partial warm-up warning
 * must remain visible, while unrelated static metadata stays cached for this model.
 */
class WarmUpCrashModel extends Model
{
    public function getTable(): string
    {
        throw new \RuntimeException('deliberate warm-up crash fixture');
    }
}
