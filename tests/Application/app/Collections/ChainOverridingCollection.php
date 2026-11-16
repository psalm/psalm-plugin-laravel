<?php

declare(strict_types=1);

namespace App\Collections;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Collection that overrides `loadMissingRelation()`, which `loadMissing()` walks a relation chain through.
 *
 * @template TKey of array-key
 * @template TModel of Model
 * @extends Collection<TKey, TModel>
 */
final class ChainOverridingCollection extends Collection
{
    /**
     * @param Collection<array-key, TModel> $models
     * @param array<array-key, mixed> $path
     */
    #[\Override]
    protected function loadMissingRelation(Collection $models, array $path): void
    {
        parent::loadMissingRelation($models, $path);
    }
}
