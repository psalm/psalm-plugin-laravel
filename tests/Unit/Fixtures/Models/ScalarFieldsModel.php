<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Exercises `$fillable` / `$guarded` / `$hidden` / `$with` / `$withCount` / `$connection`
 * so the registry builder's reflection-based readers are covered on a model that actually
 * sets them. No Application model sets these fields today, so the fixture lives here.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
final class ScalarFieldsModel extends Model
{
    protected $connection = 'reporting';

    protected $fillable = ['Name', 'EMAIL'];

    protected $guarded = ['Id'];

    protected $hidden = ['Password'];

    // Non-empty $visible is Eloquent's serialization allow-list, applied BEFORE $hidden:
    // getArrayableItems() intersects on $visible, then subtracts $hidden, so for a key in BOTH lists
    // $hidden still wins. The registry captures the raw declared list verbatim; resolving
    // visible-vs-hidden is the serialization-shape consumer's job (#923), so the case is preserved
    // like the other lists.
    protected $visible = ['Name', 'EMAIL'];

    protected $appends = ['FullName'];

    // Mixed-case cast key locks the case-preservation invariant on the cast map.
    /** @var array<string, string> */
    protected $casts = [
        'CreatedAt' => 'datetime',
    ];

    /** @var list<string> */
    protected $with = ['primaryAuthor'];

    /** @var list<string> */
    protected $withCount = ['approvedComments'];

    /** @var bool */
    public $timestamps = false;
}
