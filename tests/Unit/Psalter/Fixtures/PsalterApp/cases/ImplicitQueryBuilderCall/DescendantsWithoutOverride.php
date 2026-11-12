<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use Illuminate\Database\Eloquent\Model;

/**
 * Subclasses that only inherit Eloquent's dispatch do not stop the rewrite: the same shapes as
 * DescendantOverridesMagicCall are rewritten.
 */
class DescendantPlainBase extends Model
{
    public function ownRows(): void
    {
        $this->where('title', 'x')->get();
    }
}

final class DescendantPlainChild extends DescendantPlainBase {}

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
