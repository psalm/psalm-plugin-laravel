<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Facades;

use Psalm\LaravelPlugin\Stubs\AliasStubProvider;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;

/**
 * Registers the target's class storage under every AliasLoader alias name (#2128).
 *
 * {@see AliasStubProvider} writes each alias as `class_alias()`, and Psalm records only the alias mapping,
 * no storage. Several analyzers still read storage by the raw ALIAS name instead of unaliasing it first:
 * `ClassConstAnalyzer.php:244` (unaliased at `:145` only when `classExists()`, false for enums and interfaces),
 * `AttributesAnalyzer.php:76`, `ClassLikes::getClassConstantType()` and `Methods::getStorage()`. A missing
 * entry is an uncaught `InvalidArgumentException` that kills the whole run, or silently lost types.
 *
 * The storage object is shared, so its `name` stays the target and types print the target. A name that
 * resolves to itself is skipped: a scanned classlike (e.g. an ide-helper declaration) owns it, or Psalm
 * never registered the alias. The proper fix belongs in vimeo/psalm, where those analyzers should unalias.
 */
final class ClassAliasStorageHandler implements AfterCodebasePopulatedInterface
{
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $storages = $codebase->classlike_storage_provider;

        foreach (\array_keys(AliasStubProvider::aliases()) as $alias) {
            $resolved = $codebase->classlikes->getUnAliasedName($alias);

            if (\strtolower($resolved) === \strtolower($alias) || !$storages->has($resolved)) {
                continue;
            }

            // Always assign, so a re-run refreshes a stale bridge.
            $storages->addMore([\strtolower($alias) => $storages->get($resolved)]);
        }
    }
}
