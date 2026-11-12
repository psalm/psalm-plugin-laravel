<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use Illuminate\Database\Eloquent\Model;

final class DynamicNewModel extends Model {}

/**
 * `new $class()` with a string naming a PHP internal class makes Psalm reflect it during analysis, which leaves a
 * storage that is never populated. It cannot descend from a userland model, so it must not stop the fix.
 */
final class DynamicNewKeepsFixes
{
    public function rows(DynamicNewModel $model): void
    {
        $class = 'DomainException';
        new $class();

        $model->where('title', 'x')->get();
    }
}
