<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Declares `$casts` in Laravel's ARRAY form, which `HasAttributes::ensureCastsAreStringValues()` collapses
 * to a string while constructing the model: `[Foo::class, 'arg']` → `'Foo:arg'`, single-element → `Foo::class`.
 *
 * Warm-up reads a constructor-less instance, so it must reproduce that collapse itself. Left raw, the array
 * reaches `ModelMetadataRegistryBuilder::buildCastInfo()`'s `string $castString` and TypeErrors, withholding
 * the model's WHOLE casts section — `plain_tags` included (#1281).
 *
 * POSITIONAL, matching Laravel's own `[AsCollection::class, CustomCollection::class]` test stub: `implode(',')`
 * takes values, so argument 0 IS the collection class and an `'of' =>` key would be decorative rather than
 * meaningful. (The fluent `AsCollection::of()` emits a different string again — `AsCollection:,Foo` — and is a
 * method call, so it can only live in `casts()`, which warm-up never executes.)
 *
 * `plain_tags` is the control: a string cast the normalizer passes through untouched, so a test can tell
 * "normalization ran and was selective" from "the map was rebuilt" or "this key merely survived".
 *
 * Both array forms are expressible on every supported release — `ensureCastsAreStringValues()`'s `is_array`
 * branch is byte-identical across `illuminate/database: ^12.14 || ^13.3` — so this fixture needs no gate.
 *
 * @internal fixture used by ModelInstancePreparerTest and ModelMetadataRegistryTest
 */
final class ArrayFormCastsModel extends Model
{
    /** @var array<string, string|array<array-key, string>> */
    protected $casts = [
        'options' => [AsCollection::class, Collection::class],
        'single' => [AsCollection::class],
        'plain_tags' => 'collection',
    ];
}
