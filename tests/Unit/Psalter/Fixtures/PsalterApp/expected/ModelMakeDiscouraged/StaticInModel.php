<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use Illuminate\Database\Eloquent\Model;

class StaticInModel extends Model
{
    public static function viaStatic(): static
    {
        return new static();
    }

    public static function viaSelf(): self
    {
        return new self(['x' => 1]);
    }
}
