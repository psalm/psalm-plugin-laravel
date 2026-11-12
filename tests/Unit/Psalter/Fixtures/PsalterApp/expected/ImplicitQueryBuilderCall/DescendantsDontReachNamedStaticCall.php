<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use Illuminate\Database\Eloquent\Model;

class StaticCallBase extends Model {}

final class StaticCallChild extends StaticCallBase
{
    /**
     * @param string $method
     * @param array<array-key, mixed> $parameters
     */
    #[\Override]
    public function __call($method, $parameters): mixed
    {
        return parent::__call($method, $parameters);
    }
}

/**
 * `StaticCallBase::where()` runs `__callStatic()`, which builds `new static` of the NAMED class, so the subclass that
 * overrides `__call()` is never reached: the call is rewritten whatever subclasses exist.
 */
final class DescendantsDontReachNamedStaticCall
{
    public static function named(): void
    {
        StaticCallBase::query()->where('title', 'z')->get();
    }
}
