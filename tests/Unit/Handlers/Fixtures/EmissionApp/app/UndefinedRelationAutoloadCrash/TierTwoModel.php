<?php

declare(strict_types=1);

namespace AutoloadCrashFixture;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A real Eloquent model (loads without deprecation, so warm-up processes it normally). Its relation's
 * declared generic points at {@see DeprecatedTierTwoRelated}, and the method body passes hasOne() an
 * indirect argument so RelationMethodParser (tier 1) cannot extract the related class-string and defers
 * to the return-type generic (tier 2) in {@see \Psalm\LaravelPlugin\Handlers\Eloquent\Support\RelationResolver}.
 * Tier 2 is the path with the pre-fix autoloading `is_a(..., true)`.
 */
class TierTwoModel extends Model
{
    /**
     * @return HasOne<DeprecatedTierTwoRelated, static>
     */
    public function deprecatedRel(): HasOne
    {
        // Indirect argument (a method call, not a `::class` literal) → tier-1 parsing yields nothing,
        // forcing tier-2 resolution off this docblock generic.
        return $this->hasOne($this->relatedClass());
    }

    /** @return class-string<DeprecatedTierTwoRelated> */
    private function relatedClass(): string
    {
        return DeprecatedTierTwoRelated::class;
    }
}
