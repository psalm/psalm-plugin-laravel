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
 *
 * Also carries a cast and an accessor declaration (issue #901). The accessor handler is
 * storage-based, so it registers for the abstract base too and `reference_code` resolves on both
 * an abstract-typed receiver and a concrete child. The cast feeds the migration column/cast
 * handler — the one handler that reads getCasts() off a model INSTANCE — which is therefore
 * concrete-only; registration must skip it for the (non-instantiable) abstract base without
 * throwing. A concrete child (Contract) inherits both declarations.
 */
abstract class AbstractDocument extends Model
{
    use ComparesRank;

    /**
     * Cast declaration on the abstract base (see class docblock for why it is concrete-only).
     * Inert in the type suite (no migration provides the column); present so the fixture exercises
     * an abstract base that declares a cast — the concrete-only migration handler must skip it (#901).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['signed_at' => 'immutable_datetime'];
    }

    /**
     * Legacy accessor on the abstract base — `reference_code` resolves on an abstract-typed receiver
     * and on a concrete child that inherits it (#901; see class docblock for the storage-based split).
     */
    public function getReferenceCodeAttribute(): string
    {
        return 'REF';
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeSignedBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->whereBetween('signed_at', [$from, $to]);
    }

    /**
     * Value-returning scope declared on the abstract PARENT: `->first()` returns `?self`, and
     * `self` binds to the composing class (AbstractDocument), not the queried child. A forwarded
     * child call (Contract::query()->firstSigned()) is therefore `AbstractDocument|Builder<Contract>`
     * — the return's `self` pins to the parent while the `?? $this` fallback stays the child's
     * builder. Locks the self-expansion path of forwardedScopeReturnType (issue #1053; mirrors the
     * param `self` pinning of #1031).
     *
     * @param  Builder<self>  $query
     */
    public function scopeFirstSigned(Builder $query): ?self
    {
        return $query->whereNotNull('signed_at')->first();
    }

    /**
     * Value-returning scope whose declared return is `?static` (late static binding). A forwarded
     * child call (Contract::query()->firstSignedStatic()) pins `static` to the queried child as the
     * PLAIN class — `Contract|Builder<Contract>`, not `Contract&static` — which is exactly what the
     * `final: true` argument to TypeExpander buys on the return position (issue #1053).
     *
     * @param  Builder<static>  $query
     */
    public function scopeFirstSignedStatic(Builder $query): ?static
    {
        return $query->whereNotNull('signed_at')->first();
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
