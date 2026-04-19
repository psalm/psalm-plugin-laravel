<?php

declare(strict_types=1);

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Verbatim reproduction of koel's License facade — the motivating case for issue #787.
 * The service is bound by the user's own service provider under the string alias `License`,
 * which our Testbench app does not execute, so {@see \Psalm\LaravelPlugin\Providers\FacadeMapProvider}
 * cannot resolve it at plugin-init time. The `@see` docblock is the resolver's hook.
 *
 * @method static bool isPlus()
 * @method static bool isCommunity()
 * @see \App\Services\LicenseService
 */
class License extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return 'License';
    }
}
