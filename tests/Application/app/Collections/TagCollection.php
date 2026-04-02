<?php

declare(strict_types=1);

namespace App\Collections;

use Illuminate\Database\Eloquent\Collection;

/**
 * Custom collection for Tag models, declared via the $collectionClass property.
 *
 * @template TKey of array-key
 * @template TModel
 * @extends Collection<TKey, TModel>
 */
final class TagCollection extends Collection {}
