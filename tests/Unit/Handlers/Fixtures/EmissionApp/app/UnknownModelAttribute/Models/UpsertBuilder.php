<?php

declare(strict_types=1);

namespace KnownAttributeFixture\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Userland `upsert()` override: it may map keys before delegating, so a key that matches no column
 * here can still be valid input.
 *
 * @extends Builder<Post>
 */
final class UpsertBuilder extends Builder
{
    /**
     * @param array<array-key, mixed> $values
     * @param array<array-key, string>|string $uniqueBy
     * @param array<array-key, mixed>|null $update
     */
    #[\Override]
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        return parent::upsert($values, $uniqueBy, $update);
    }
}
