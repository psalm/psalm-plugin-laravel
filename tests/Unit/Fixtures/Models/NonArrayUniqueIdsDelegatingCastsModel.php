<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @internal fixture used by ModelMetadataRegistryTest */
final class NonArrayUniqueIdsDelegatingCastsModel extends Model
{
    use HasUuids;

    /**
     * Kept untyped so the test can violate Laravel's documented array return through Reflection.
     *
     * @var array<array-key, mixed>
     */
    public static $uniqueIdColumns = [];

    /** @var array<string, string> */
    protected $casts = [
        'enabled' => 'boolean',
    ];

    /** @return array<array-key, mixed> */
    #[\Override]
    public function uniqueIds()
    {
        return self::$uniqueIdColumns;
    }

    /** @return array<string, string> */
    #[\Override]
    public function getCasts(): array
    {
        $casts = parent::getCasts();

        return ['delegated' => isset($casts['enabled']) ? 'boolean' : 'string'];
    }
}
