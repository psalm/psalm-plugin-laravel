<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Abstract base carrying a docblock-only pseudo-scope. Psalm's
 * Populator::populateDataFromParentClass copies this class-level @method tag into a concrete
 * child's LOCAL pseudo_static_methods (when the child has no real same-named method), so
 * BuilderScopeHandler::resolvePseudoScopeParams resolves a parent-declared pseudo-scope on the
 * child from the child's own maps. Pins that inherited path (PseudoScopeModel extends this).
 *
 * @method static Builder<static> scopeFromParentDoc(Builder $query)
 */
abstract class AbstractPseudoScopeBase extends Model {}
