<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Abstract parent declaring scopes: instance scope calls on a child's builder
 * must resolve params from the declaring (parent) class.
 */
abstract class AbstractDocument extends Model
{
    /**
     * @param  Builder<self>  $query
     */
    public function scopeSignedBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->whereBetween('signed_at', [$from, $to]);
    }
}
