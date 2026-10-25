<?php

declare(strict_types=1);

namespace KnownAttributeFixture\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Userland creation override: it may rename keys before delegating, so a key that matches no column
 * here can still be valid input. The inherited `createQuietly()` calls `$this->create()` too.
 *
 * @extends Builder<Post>
 */
final class ImportBuilder extends Builder
{
    /** @param array<string, mixed> $attributes */
    #[\Override]
    public function create(array $attributes = [])
    {
        if (isset($attributes['headline'])) {
            $attributes['title'] = $attributes['headline'];
            unset($attributes['headline']);
        }

        return parent::create($attributes);
    }
}
