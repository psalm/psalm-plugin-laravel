<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** @internal fixture used by ModelMetadataRegistryTest */
final class BootDependentKeyModel extends Model
{
    private static bool $usesStringKeys = false;

    protected static function booted(): void
    {
        self::$usesStringKeys = true;
    }

    public static function resetKeyConfiguration(): void
    {
        self::$usesStringKeys = false;
        self::clearBootedModels();
    }

    #[\Override]
    public function getKeyType(): string
    {
        return self::$usesStringKeys ? 'string' : 'int';
    }

    #[\Override]
    public function getIncrementing(): bool
    {
        return !self::$usesStringKeys;
    }
}
