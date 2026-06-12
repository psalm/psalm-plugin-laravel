<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ComparesRank;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Abstract parent declaring scopes: instance scope calls on a child's builder
 * must resolve params from the declaring (parent) class.
 *
 * Composes ComparesRank HERE (not on the concrete children) so the trait's `self`-typed
 * scope params reproduce the trait-on-parent hierarchy: PHP binds a trait method's `self`
 * to the *composing* class — AbstractDocument — fixed at composition time, NOT to whichever
 * child subclass runs the query. So `Contract::query()->rankedAbove($receipt)` is
 * runtime-valid (Contract and Receipt are sibling children of AbstractDocument) and the
 * plugin must accept it (issue #1031).
 */
abstract class AbstractDocument extends Model
{
    use ComparesRank;

    /**
     * @param  Builder<self>  $query
     */
    public function scopeSignedBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->whereBetween('signed_at', [$from, $to]);
    }

    /**
     * Control for a `self`-typed scope param declared DIRECTLY on the abstract parent
     * (not via a trait). Psalm resolves this `self` to AbstractDocument at scan time, so the
     * handler's re-expansion is idempotent — the directly-declared and trait-hosted paths
     * must agree that `self` is the composing class. A sibling child is therefore accepted.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSupersedes(Builder $query, self $document): void
    {
        $query->whereKeyNot($document->getKey());
    }
}
