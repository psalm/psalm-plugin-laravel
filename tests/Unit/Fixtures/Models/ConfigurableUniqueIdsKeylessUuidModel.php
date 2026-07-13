<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @internal fixture used by ModelMetadataRegistryTest */
final class ConfigurableUniqueIdsKeylessUuidModel extends Model
{
    use HasUuids;

    /** @var list<string> */
    public static array $uniqueIdColumns = [];

    protected $primaryKey;

    /** @var bool */
    public $incrementing = false;

    /** @return list<string> */
    #[\Override]
    public function uniqueIds(): array
    {
        return self::$uniqueIdColumns;
    }
}
