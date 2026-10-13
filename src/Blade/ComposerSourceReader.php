<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use Illuminate\View\Factory;

/**
 * Reads which class and method each composer or creator of a view runs, without running any. Only
 * the closure `Factory::buildClassEventCallback()` builds is recognised; any other listener makes
 * the view's sources unknown. The class is read as written: container rebinding and factory or
 * dispatcher subclasses are not modelled.
 *
 * @internal
 */
final class ComposerSourceReader
{
    /**
     * @param list<string> $viewNames
     *
     * @return array<array-key, list<array{0: string, 1: string}>|null> each view a composer or creator
     *         reaches => class and lowercased method of each, null when one is unreadable
     */
    public static function read(?Factory $factory, array $viewNames): array
    {
        $events = $factory?->getDispatcher();
        $sources = [];

        foreach ($viewNames as $viewName) {
            $found = self::sourcesFor($events, $viewName);

            if ($found !== []) {
                $sources[$viewName] = $found;
            }
        }

        return $sources;
    }

    /** @return list<array{0: string, 1: string}>|null */
    private static function sourcesFor(?DispatcherContract $events, string $viewName): ?array
    {
        $names = ["composing: {$viewName}", "creating: {$viewName}"];

        try {
            // Only Laravel's dispatcher lists its listeners; another one shows only that one exists.
            if (!$events instanceof Dispatcher) {
                return $events instanceof DispatcherContract && ($events->hasListeners($names[0]) || $events->hasListeners($names[1])) ? null : [];
            }

            /** @var array<string, list<object|string|array|null>> $exact */
            $exact = $events->getRawListeners();
            /** @var array<string, list<object|string|array|null>> $wildcards no getter; matched as `getWildcardListeners()` does */
            $wildcards = (new \ReflectionProperty(Dispatcher::class, 'wildcards'))->getValue($events);
            $sources = [];

            foreach ($names as $name) {
                $listeners = $exact[$name] ?? [];

                // For a wildcard view name `addEventListener()` wraps the class callback to pass it the view.
                foreach ($wildcards as $pattern => $wrappers) {
                    foreach (Str::is($pattern, $name) ? $wrappers : [] as $wrapper) {
                        $listeners[] = self::captured($wrapper, 'addEventListener')['callback'] ?? null;
                    }
                }

                foreach ($listeners as $listener) {
                    $captured = self::captured($listener, 'buildClassEventCallback');

                    if (!isset($captured['class'], $captured['method'])) {
                        return null;
                    }

                    $sources[] = [\ltrim($captured['class'], '\\'), \strtolower($captured['method'])];
                }
            }

            return $sources;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * What a closure captured, if it was declared inside the named `Factory` method: only the
     * closures `ManagesEvents` builds are, and they capture exactly these.
     *
     * @return array{class?: string, method?: string, callback?: \Closure}
     */
    private static function captured(mixed $listener, string $factoryMethod): array
    {
        if (!$listener instanceof \Closure) {
            return [];
        }

        $function = new \ReflectionFunction($listener);
        $declaring = new \ReflectionMethod(Factory::class, $factoryMethod);

        if ($function->getFileName() !== $declaring->getFileName()
            || $function->getStartLine() < $declaring->getStartLine()
            || $function->getEndLine() > $declaring->getEndLine()
        ) {
            return [];
        }

        /** @var array{class?: string, method?: string, callback?: \Closure} */
        return $function->getStaticVariables();
    }
}
