<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @internal fixture used by ModelMetadataRegistryTest */
final class ApplicationIdentifierFailureModel extends Model
{
    use HasUuids;

    public static string $failure = 'null-offset';

    /** @var array<string, string> */
    protected $casts = [
        'enabled' => 'boolean',
    ];

    /** @return array<array-key, mixed> */
    #[\Override]
    public function uniqueIds()
    {
        return NonArrayUniqueIdsDelegatingCastsModel::$uniqueIdColumns;
    }

    #[\Override]
    public function getIncrementing(): bool
    {
        if (self::$failure === 'null-offset') {
            throw new \RuntimeException('Using null as an array offset is deprecated');
        }

        throw new \TypeError('in_array(): Argument #2 ($haystack) must be of type array, string given');
    }

    /** @return array<string, string> */
    #[\Override]
    public function getCasts(): array
    {
        return parent::getCasts();
    }
}
