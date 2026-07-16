<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Abstract base carrying `#[Hidden]`/`#[Appends]` so {@see AttributeConfiguredChild} can prove the
 * `classAttribute()` ancestor walk (mirroring `Model::resolveClassAttribute()`) resolves an inherited
 * attribute. Laravel 13.0+ only.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
#[Hidden('base_hidden')]
#[Appends('base_append')]
abstract class AttributeConfiguredBase extends Model {}
