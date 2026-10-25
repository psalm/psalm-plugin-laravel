<?php

declare(strict_types=1);

namespace Illuminate\Review;

use Illuminate\Database\Eloquent\Builder;
use KnownAttributeFixture\Models\Post;

/**
 * Userland class that merely lives in an `Illuminate\` namespace: it is not a framework declaration,
 * so its `newModelInstance()` override must still make the creation path undecidable.
 *
 * @extends Builder<Post>
 */
final class ImportBuilder extends Builder
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
