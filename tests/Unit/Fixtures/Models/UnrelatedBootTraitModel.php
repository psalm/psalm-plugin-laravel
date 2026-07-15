<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

trait TracksBootForTesting
{
    public static bool $bootHookRan = false;

    public static function bootTracksBootForTesting(): void
    {
        self::$bootHookRan = true;
    }
}

/** @internal fixture used by ModelMetadataRegistryTest */
final class UnrelatedBootTraitModel extends Model
{
    use TracksBootForTesting;
}
