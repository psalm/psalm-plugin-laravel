<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `HasAttributes::ensureCastsAreStringValues()` only rewrites its `object`/`array` (multi-element)
 * match arms; every other shape hits the unconditional `default => $cast` identity arm and survives
 * construction untouched. Left raw, a non-string value reaches
 * `ModelMetadataRegistryBuilder::buildCastInfo()`'s `string $castString` under `strict_types=1` and
 * TypeErrors, withholding the model's WHOLE casts section (#1290) — `good_col` included, which is the
 * regression this fixture guards against.
 *
 * `nested_col` is a ONE-element outer array (its sole element is itself an array), so it takes the
 * OPPOSITE path from the scalars above: the normalizer's `count() === 1` collapse (see
 * {@see ArrayFormCastsModel}) fires and returns that inner array unchanged — still non-string, just
 * via a different arm. Do NOT "flatten" this to a plain multi-element array like `['a', 'b']`: that
 * collapses to the STRING `'a:b'` and silently deletes this key's coverage.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
final class NonStringDeclaredCastsModel extends Model
{
    /** @var array<string, string|int|bool|float|array<int, array<int, string>>|null> */
    protected $casts = [
        'good_col' => 'int',
        'null_col' => null,
        'int_col' => 123,
        'bool_col' => true,
        'float_col' => 1.5,
        'nested_col' => [['a']],
    ];
}
