<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Declares a `casts()` method, which `HasAttributes::initializeHasAttributes()` EXECUTES at construction.
 *
 * That execution is the whole reason the warm-up replay stands in for that one initializer instead of
 * invoking it (see
 * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelInstancePreparer::applyAppendsAttribute()}) —
 * not because `casts()` is user code, but because it is the one initializer input the registry ALREADY has
 * statically: {@see \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\CastsMethodParser} AST-parses it and the
 * builder merges that result last. The cast is therefore absent from the replayed instance and present on a
 * constructed one — the contrast the consuming test asserts.
 *
 * Not version-gated: the `casts()` merge is in `initializeHasAttributes()` across the whole supported range.
 *
 * @internal fixture used by ModelInstancePreparerTest
 */
final class CastsMethodModel extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['from_casts_method' => 'array'];
    }
}
