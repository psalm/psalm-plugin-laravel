<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class StaticInModel extends Model
{
    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', 1);
    }

    public static function viaStatic(): void
    {
        static::where('active', 1)->get();
    }

    public static function viaSelf(): void
    {
        self::where('title', 'x')->get();
    }
}
