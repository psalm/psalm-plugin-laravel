<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Keyless model that inherits Eloquent's `$incrementing = true` default. The two settings conflict:
 * Laravel's base getCasts() tries to use the missing key name as an array offset.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
final class ConflictingIncrementingKeylessModel extends Model
{
    protected $primaryKey;

    /** @var array<string, string> */
    protected $casts = [
        'enabled' => 'boolean',
    ];
}
