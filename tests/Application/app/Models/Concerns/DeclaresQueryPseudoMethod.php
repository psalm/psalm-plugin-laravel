<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Reproduces the trait-shaped trigger from Koel's SupportsDeleteWhereValueNotIn:
 * a zero-param @method static pseudo for query() shadows a model's overriding
 * signature unless the plugin removes the entry post-populator.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/795
 *
 * @method static Builder query()
 */
trait DeclaresQueryPseudoMethod {}
