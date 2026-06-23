<?php

declare(strict_types=1);

namespace AppendsFixture\Models;

use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Model;

/**
 * Clean: `options` is backed by a first-party Castable cast (`AsCollection`). The registry classifies
 * this as CastShape::Primitive, so the earlier shape-based check would have falsely reported it; the
 * any-cast-backs rule must NOT report it. End-to-end guard for that false-positive fix.
 */
class CastBackedAppendModel extends Model
{
    /** @var list<string> */
    protected $appends = ['options'];

    /** @var array<string, string> */
    protected $casts = ['options' => AsCollection::class];
}
