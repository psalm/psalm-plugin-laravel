<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Produces both identifier conflicts at once: no non-empty unique-ID column and an explicitly true
 * incrementing getter. The registry must combine them into one warning and recover base getCasts().
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
final class NullKeyUuidModel extends Model
{
    use HasUuids;

    protected $primaryKey;

    #[\Override]
    public function getIncrementing(): bool
    {
        return true;
    }
}
