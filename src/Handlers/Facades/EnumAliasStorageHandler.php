<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Facades;

use Psalm\LaravelPlugin\Stubs\AliasStubProvider;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;

/**
 * Keeps `Alias::Case` from crashing Psalm for an enum registered with AliasLoader (#2128).
 *
 * {@see AliasStubProvider} writes such an alias as `class_alias()`, which makes Psalm resolve `Alias::Case`
 * to the enum. Psalm's constant-fetch analyzer then reads the storage by the alias name, and that read throws
 * `InvalidArgumentException` (an uncaught crash of the whole run) when the enum was never scanned as a class:
 * the enum is declared outside `<projectFiles>` and no Composer autoloader maps it, so Psalm only knows it
 * from the stub. Registering the enum's storage under the alias name closes that gap. Upstream: Psalm's
 * `ClassConstAnalyzer` unaliases the name only for targets `classExists()` accepts, which excludes enums.
 */
final class EnumAliasStorageHandler implements AfterCodebasePopulatedInterface
{
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $storages = $event->getCodebase()->classlike_storage_provider;

        foreach (AliasStubProvider::enumAliases() as $alias => $enum) {
            if ($storages->has($alias) || !$storages->has($enum)) {
                continue;
            }

            $storages->addMore([\strtolower($alias) => $storages->get($enum)]);
        }
    }
}
