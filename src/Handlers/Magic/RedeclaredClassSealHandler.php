<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Magic;

use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;

/**
 * Restores the `sealAllMethods` default on vendor classes the plugin re-declares in a stub.
 *
 * Psalm applies `sealAllMethods` only to user-defined classes (`ClassLikeStorage::hasSealedMethods()`),
 * and a stub re-declaration marks the class non-user-defined, so `__call` (here `Macroable::__call()`,
 * which throws unless a macro is registered) silently accepts any method name. A `@psalm-seal-methods`
 * tag in the stub would seal it even where the project set `sealAllMethods="false"` and propagate the
 * explicit seal to user subclasses, so the config value is copied instead. An explicit seal tag on the
 * stub (`sealed_methods !== null`) wins. Registered macros stay callable: MacroHandler declares them
 * as pseudo-methods, which Psalm consults before reporting on a sealed class.
 */
final class RedeclaredClassSealHandler implements AfterCodebasePopulatedInterface
{
    /** Only classes with a throwing `__call` belong here; a class without one needs no seal. */
    private const CLASSES = [
        'Laravel\Ai\Tools\Request',
    ];

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $provider = $codebase->classlike_storage_provider;

        foreach (self::CLASSES as $fqcn) {
            if (!$provider->has($fqcn)) {
                continue;
            }

            $storage = $provider->get($fqcn);
            $storage->sealed_methods ??= $codebase->config->seal_all_methods;
        }
    }
}
