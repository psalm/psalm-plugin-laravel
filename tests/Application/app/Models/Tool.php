<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Equipment kept by the shop and checked out to mechanics. Concrete (not abstract)
 * because {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler}
 * skips abstract classes — an abstract base would never reach the relation provider.
 *
 * Acts as the base class for the {@see PowerTool} subclass so `parent::class` in
 * relation factories on PowerTool can be exercised; concurrently exercises
 * `self::class` and `static::class` substitution in factories on this class itself.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/879
 */
class Tool extends Model
{
    protected $table = 'tools';

    /**
     * Replacement tool kept on the same shelf — modeled with `self::class` so the
     * parser must substitute the keyword with the declaring class FQCN.
     */
    public function replacementTool(): HasOne
    {
        return $this->hasOne(self::class);
    }

    /**
     * Same shape but using `static::class` — pinned conservatively to the declaring
     * class (see {@see \Psalm\LaravelPlugin\Handlers\Eloquent\RelationMethodParser::resolveClassConstFetch}).
     */
    public function lateBoundReplacement(): HasOne
    {
        return $this->hasOne(static::class);
    }

    /**
     * Mechanics who have used a successor tool of this one. Through-relation with the
     * intermediate slot at `self::class` — exercises the parser's positionalIndex=1
     * threading. The semantics are intentionally sparse; the relation's value is in
     * pinning the through-slot keyword substitution.
     */
    public function successorMechanics(): HasManyThrough
    {
        return $this->hasManyThrough(Mechanic::class, self::class);
    }
}
