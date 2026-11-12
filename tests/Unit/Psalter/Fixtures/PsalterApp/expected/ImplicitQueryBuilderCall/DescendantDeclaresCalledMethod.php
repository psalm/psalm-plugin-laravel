<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use Illuminate\Database\Eloquent\Model;

class DeclaresWhereBase extends Model {}

final class DeclaresWhereChild extends DeclaresWhereBase
{
    public function where(string $column, int $value): int
    {
        return $value;
    }
}

/**
 * The object behind `$base` can be the scanned subclass, whose own `where()` is what the call runs: the inserted
 * `newQuery()->` would skip it. Reported, never rewritten.
 */
final class DescendantDeclaresCalledMethod
{
    public function rows(DeclaresWhereBase $base): void
    {
        $base->where('title', 'x');
    }
}
