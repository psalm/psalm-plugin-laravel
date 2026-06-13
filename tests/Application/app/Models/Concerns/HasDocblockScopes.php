<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * A docblock-only pseudo-scope hosted on a TRAIT — the literal issue #1054 shape ("a scope a
 * package/trait documents via @method"). There is no scopeXxx() body; only the class-level tag.
 *
 * Psalm's Populator::populateDataFromTrait merges this tag into the using model's LOCAL
 * pseudo_static_methods, so BuilderScopeHandler::resolvePseudoScopeParams resolves it from the
 * model's own maps just like a directly-declared tag.
 *
 * The `self $owner` param (after the stripped $query) is left raw by Psalm for a trait tag, so it
 * must expand to the USING model — this pins expandScopeParamSelfReferences running on a
 * pseudo-method param (the param SOURCE differs from a real scope's reflected params).
 *
 * @method static Builder<static> scopeFromTraitDoc(Builder $query, self $owner)
 *
 * @psalm-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasDocblockScopes {}
