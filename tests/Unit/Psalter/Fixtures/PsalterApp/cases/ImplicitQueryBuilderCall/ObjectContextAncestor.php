<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use Illuminate\Database\Eloquent\Model;

/**
 * The related class may also be an ancestor of the target: `$this` inside the base class can be the child
 * at runtime, so `ObjectContextAncestorChild::where()` may run `$this->__call()`. Reported, never rewritten.
 */
abstract class ObjectContextAncestorBase extends Model
{
    public function childRows(): void
    {
        ObjectContextAncestorChild::where('title', 'x')->get();
    }
}

final class ObjectContextAncestorChild extends ObjectContextAncestorBase {}
