<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Composes HasArchivedRevisions one level down, so its revisions() competes with ComposesRevisions'
 * nested one (#1613).
 *
 * @psalm-require-extends \Illuminate\Database\Eloquent\Model
 */
trait ComposesArchivedRevisions
{
    use HasArchivedRevisions;
}
