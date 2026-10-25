<?php

declare(strict_types=1);

namespace KnownAttributeFixture\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Overrides the delegated hook, not a listed creation method: `make()` / `create()` build the model
 * through `newModelInstance()`, so the key mapping here applies to them.
 *
 * @extends Builder<Post>
 */
final class HookBuilder extends Builder
{
    /** @param array<string, mixed> $attributes */
    #[\Override]
    public function newModelInstance($attributes = [])
    {
        if (isset($attributes['headline'])) {
            $attributes['title'] = $attributes['headline'];
            unset($attributes['headline']);
        }

        return parent::newModelInstance($attributes);
    }
}
