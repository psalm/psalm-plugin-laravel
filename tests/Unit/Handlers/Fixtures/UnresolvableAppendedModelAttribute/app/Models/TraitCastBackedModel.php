<?php

declare(strict_types=1);

namespace AppendsFixture\Models;

use AppendsFixture\Concerns\HasMeta;
use Illuminate\Database\Eloquent\Model;

/**
 * Clean: `meta` is backed by a class cast (`AsArrayObject`) that the `HasMeta` trait merges via its
 * `initializeHasMeta()` construction hook, not a `$casts` property. Warm-up replays trait initializers,
 * so the cast is present and the append must NOT be reported. End-to-end guard for the
 * trait-initializer class-cast false positive.
 */
class TraitCastBackedModel extends Model
{
    use HasMeta;

    /** @var list<string> */
    protected $appends = ['meta'];
}
