<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * A receiver that merely contains `$this` still reads the closure's binding: `Closure::call()` can rebind
 * `$this` to an object that is not a model, whose `where()` is not Eloquent's. Reported, never rewritten.
 */
final class CloneThisInClosure extends Model
{
    public function callback(): \Closure
    {
        return fn() => (clone $this)->where('title', 'x')->get();
    }
}
