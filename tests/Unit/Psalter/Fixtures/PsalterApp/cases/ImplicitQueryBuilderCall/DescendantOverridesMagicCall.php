<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use Illuminate\Database\Eloquent\Model;

/**
 * The object behind `$base->where()` or `$this->where()` can be any scanned subclass, and a subclass that
 * overrides `__call()` is the code the forwarded call reaches: the inserted `newQuery()->` would skip it. Both
 * reported, never rewritten.
 */
class DescendantMagicBase extends Model
{
    public function ownRows(): void
    {
        $this->where('title', 'x')->get();
    }
}

final class DescendantMagicChild extends DescendantMagicBase
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

final class DescendantMagicCaller
{
    public function typed(DescendantMagicBase $base): void
    {
        $base->where('title', 'y')->get();
    }
}
