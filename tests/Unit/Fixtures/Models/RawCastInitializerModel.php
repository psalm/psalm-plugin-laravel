<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Concerns\WritesRawCast;

/**
 * Drives the {@see \Tests\Psalm\LaravelPlugin\Unit\Fixtures\Concerns\WritesRawCast} trait initializer,
 * which writes an int cast value straight into `$this->casts`, bypassing normalization (#1290).
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
final class RawCastInitializerModel extends Model
{
    use WritesRawCast;
}
