<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use Illuminate\Database\Eloquent\Model;

/**
 * `self::make()` creates the called child through late static binding, and `static::make()` in an instance
 * method dispatches to `$this->__call()` and keeps the instance's connection: `new self` / `new static`
 * would change both, so neither is reported nor rewritten.
 */
class StaticInModel extends Model
{
    public static function viaStatic(): static
    {
        return static::make();
    }

    public static function viaSelf(): self
    {
        return self::make(['x' => 1]);
    }

    public function sibling(): static
    {
        return static::make();
    }
}
