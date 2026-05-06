<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Power-driven specialty {@see Tool} (drills, impact wrenches, etc.). Exists to
 * exercise `parent::class` resolution on a relation factory — `parent::class`
 * inside a method on this class must resolve to the {@see Tool} FQCN, not leak
 * the literal `'parent'` keyword.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/879
 */
final class PowerTool extends Tool
{
    /**
     * The non-powered ancestor tools this power tool replaced. `parent::class`
     * resolves to {@see Tool}, so the relation reads `HasMany<Tool, PowerTool>`.
     */
    public function ancestorTools(): HasMany
    {
        return $this->hasMany(parent::class, 'replaced_id');
    }

    /**
     * Mechanics who used the lineage of non-powered tools this power tool replaced.
     * `parent::class` at the through intermediate slot (positionalIndex=1) plus the
     * named-arg form together exercise both branches of `extractClassStringArg`.
     */
    public function lineageMechanics(): HasManyThrough
    {
        return $this->hasManyThrough(related: Mechanic::class, through: parent::class);
    }
}
