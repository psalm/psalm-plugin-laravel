<?php

declare(strict_types=1);

namespace App\Collections;

use Illuminate\Database\Eloquent\Collection;

/**
 * Custom collection for Secret models — used to test newCollection() override support.
 *
 * @template TKey of array-key
 * @template TModel
 * @extends Collection<TKey, TModel>
 */
class SecretCollection extends Collection {}
