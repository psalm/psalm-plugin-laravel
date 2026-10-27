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
 * to the enum. Psalm's constant-fetch analyzer then reads the storage by the ALIAS name
 * (`ClassConstAnalyzer.php:244`), and that read throws `InvalidArgumentException`, an uncaught crash of the
 * whole run. The enum's own storage is present; the entry under the alias key is what is missing. The
 * analyzer unaliases the name first (`:145-146`) only when `classExists()` is true, and `ClassLikes`
 * records an enum as `existing_classes_lc = false` (`addFullyQualifiedEnumName`, `:265`) when Psalm found
 * it through its reflection route instead of a project file: an enum outside `<projectFiles>` that no
 * Composer autoloader maps (`packages/`, `Modules/`). Registering the enum's storage under the alias name
 * closes that gap. Upstream: `ClassConstAnalyzer` should unalias enum targets too.
 *
 * The `has($enum)` guard is defensive: with no storage for the target there is nothing to register.
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
