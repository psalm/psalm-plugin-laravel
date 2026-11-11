<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use Illuminate\Database\Eloquent\Model;

/**
 * `$this->where()` in a non-static closure reads the closure's `$this`, which `Closure::call()` / `bindTo()` can
 * replace with another model (its table and connection): reported, never rewritten. Outside a closure the receiver
 * is the method's own `$this`, so the call is rewritten.
 */
final class ThisReceiverInClosure extends Model
{
    public function direct(): void
    {
        $this->where('title', 'x')->get();
    }

    public function inClosure(): void
    {
        $rows = function (): void {
            $this->where('title', 'y')->get();
        };
        $rows();
    }
}
