<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Resolves the revisions() conflict itself (#1613): a model composing only this trait runs
 * HasArchivedRevisions::revisions(), while the other HasRevisions relations arrive by a single path.
 *
 * @psalm-require-extends \Illuminate\Database\Eloquent\Model
 */
trait PicksArchivedRevisions
{
    use HasRevisions;
    use HasArchivedRevisions {
        HasArchivedRevisions::revisions insteadof HasRevisions;
    }
}
