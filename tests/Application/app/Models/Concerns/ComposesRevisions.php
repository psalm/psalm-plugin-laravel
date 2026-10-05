<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Composes HasRevisions one level down (#1613): its relations stay resolvable through the nested
 * trait, except where an `insteadof` on the composing model picks a competing declaration.
 *
 * @psalm-require-extends \Illuminate\Database\Eloquent\Model
 */
trait ComposesRevisions
{
    use HasRevisions;
}
