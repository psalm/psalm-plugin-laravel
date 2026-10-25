<?php

declare(strict_types=1);

namespace KnownAttributeFixture\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Non-generic custom builder: the model is bound through `@extends`.
 *
 * @extends Builder<Post>
 */
final class PostBuilder extends Builder
{
    /** `$this` of a non-generic builder resolves to Post — typo must be flagged. */
    public function createWithTypo(): Post
    {
        return $this->create(['typo_this_builder' => 'x', 'body' => 'y']);
    }
}
