<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Abstract base that declares BOTH a custom Eloquent builder (via the static $builder property)
 * and SoftDeletes — the archetype behind issue #901's builder regression. Mirrors BookStack's
 * `Entity`, which sets `protected static string $builder = EntityQueryBuilder::class` and composes
 * SoftDeletes.
 *
 * SoftDeletes contributes the `withTrashed()`/`onlyTrashed()` @method pseudo-methods. Custom
 * builder detection (handleTraitBuilderMethods) normally STRIPS those pseudo-methods so they
 * resolve through the custom builder instead. That is correct for a concrete model, but an abstract
 * base is commonly queried through the base `Builder<AbstractSoftDeletable>`, from which
 * BuilderScopeHandler resolves the pseudo-methods, so stripping them regresses `->onlyTrashed()`
 * to a mixed call.
 * ModelRegistrationHandler therefore skips custom builder detection for abstract bases; this
 * fixture is the regression guard (see AbstractModelCustomBuilderTest).
 */
abstract class AbstractSoftDeletable extends Model
{
    use SoftDeletes;

    protected static string $builder = SoftDeletableBuilder::class;
}
