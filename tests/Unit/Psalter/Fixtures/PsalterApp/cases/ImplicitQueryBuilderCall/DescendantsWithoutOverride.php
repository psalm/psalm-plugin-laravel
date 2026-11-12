<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Subclasses that leave Eloquent's dispatch alone do not stop the rewrite: the same shapes as
 * DescendantOverridesMagicCall are rewritten, also with a subclass that overrides `newQuery()` (it is on both
 * paths).
 */
class DescendantPlainBase extends Model
{
    public function ownRows(): void
    {
        $this->where('title', 'x')->get();
    }
}

final class DescendantPlainChild extends DescendantPlainBase {}

final class DescendantPlainNewQueryChild extends DescendantPlainBase
{
    /** @return Builder<static> */
    #[\Override]
    public function newQuery()
    {
        return parent::newQuery()->where('tenant_id', 1);
    }
}

final class DescendantPlainCaller
{
    public function typed(DescendantPlainBase $base): void
    {
        $base->where('title', 'y')->get();
    }

    public static function named(): void
    {
        DescendantPlainBase::where('title', 'z')->get();
    }
}
