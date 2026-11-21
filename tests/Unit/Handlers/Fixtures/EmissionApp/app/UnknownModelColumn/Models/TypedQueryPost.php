<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TypedQueryPost extends Model
{
    protected $table = 'posts';

    /** Return-type narrowing override; the body is not inspected, so the model is declined. */
    #[\Override]
    public static function query(): Builder
    {
        $builder = parent::query();
        \assert($builder instanceof Builder);

        return $builder;
    }
}
