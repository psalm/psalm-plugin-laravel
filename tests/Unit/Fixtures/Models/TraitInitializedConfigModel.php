<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Concerns\MergesTraitConfig;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Concerns\SeedsCastViaAttribute;

/**
 * Drives {@see \Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder}'s
 * trait-initializer replay across BOTH discovery branches: MergesTraitConfig merges the `meta` class cast
 * and `trait_fillable` via a conventionally-named initializer, while SeedsCastViaAttribute merges the
 * `via_attr` class cast via a `#[Initialize]`-tagged, non-conventionally-named one. A constructor-less
 * warm-up skips both unless replayed. The class-level `$fillable` proves the trait merge unions, not clobbers.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
final class TraitInitializedConfigModel extends Model
{
    use MergesTraitConfig;
    use SeedsCastViaAttribute;

    /** @var list<string> */
    protected $appends = ['meta'];

    /** @var list<string> */
    protected $fillable = ['class_fillable'];
}
