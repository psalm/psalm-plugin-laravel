<?php

declare(strict_types=1);

namespace App\Collections;

use Illuminate\Database\Eloquent\Collection;

/**
 * Custom collection for Post models — used to test #[CollectedBy] support.
 *
 * @template TKey of array-key
 * @template TModel
 * @extends Collection<TKey, TModel>
 */
class PostCollection extends Collection
{
    /**
     * Get only published posts.
     *
     * @psalm-return static
     */
    public function published(): static
    {
        return $this->filter(fn(\App\Models\Post $post): bool => (bool) $post->getAttribute('published'));
    }
}
