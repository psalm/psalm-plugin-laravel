--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The narrowing targets CacheManager and the Cache facade only. It must not leak
 * onto the Repository contract, the Factory contract, or untargeted facade methods.
 */
function contract_repository_has_no_flexible(Repository $repository): void
{
    $repository->flexible('things', [300, 3600], static fn (): int => 1);
}

function factory_store_stays_contract(Factory $factory): void
{
    $_store = $factory->store();
    /** @psalm-check-type-exact $_store = \Illuminate\Contracts\Cache\Repository */
}

function exact_int(int $value): int
{
    return $value;
}

function exact_string(string $value): string
{
    return $value;
}

function untargeted_facade_method_unchanged(): void
{
    $_remember = Cache::remember('k', 60, static fn (): int => exact_int(1));
    /** @psalm-check-type-exact $_remember = int */

    $_rememberForever = Cache::rememberForever('k', static fn (): string => exact_string('x'));
    /** @psalm-check-type-exact $_rememberForever = string */
}
?>
--EXPECTF--
UndefinedInterfaceMethod on line %d: Method Illuminate\Contracts\Cache\Repository::flexible does not exist
