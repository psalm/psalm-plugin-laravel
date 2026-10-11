<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Illuminate\Contracts\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use Illuminate\View\Factory;

/**
 * Reads off the booted view factory which class and method each view composer or creator will run
 * for a view, without invoking any of them.
 *
 * A listener counts only when it is the very closure `Factory::buildClassEventCallback()` builds
 * (identified by where it was declared and by its captured variables, never by a name or a guess):
 * a user closure, a string or array registered straight on the dispatcher, a class the container
 * binds to something else, or a dispatcher other than Laravel's, cannot be read, so the view's
 * sources are unknown.
 *
 * @internal
 */
final class ComposerSourceReader
{
    /** @psalm-mutation-free */
    public function __construct(
        private readonly Container $app,
        private readonly ?Factory $factory,
    ) {}

    /**
     * @return list<array{0: string, 1: string}>|null class and lowercased method of every composer
     *         and creator reaching the view; empty when none does, null when one is unreadable
     */
    public function sourcesFor(string $viewName): ?array
    {
        $events = $this->factory?->getDispatcher();

        if ($events === null) {
            return [];
        }

        try {
            if (!$events instanceof Dispatcher) {
                return $events->hasListeners("composing: {$viewName}") || $events->hasListeners("creating: {$viewName}") ? null : [];
            }

            $sources = [];

            foreach (["composing: {$viewName}", "creating: {$viewName}"] as $event) {
                foreach ($this->listenersFor($events, $event) as $listener) {
                    $source = $this->classMethodOf($listener);

                    if ($source === null) {
                        return null;
                    }

                    $sources[] = $source;
                }
            }

            return $sources;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<\Closure|string>
     */
    private function listenersFor(Dispatcher $events, string $event): array
    {
        /** @var array<string, list<\Closure|string>> $raw */
        $raw = $events->getRawListeners();
        $listeners = $raw[$event] ?? [];

        // The same test `Dispatcher::hasWildcardListeners()` applies; `$wildcards` has no getter.
        /** @var array<string, list<\Closure|string>> $wildcards */
        $wildcards = (new \ReflectionProperty(Dispatcher::class, 'wildcards'))->getValue($events);

        foreach ($wildcards as $pattern => $wildcard) {
            if (Str::is($pattern, $event)) {
                \array_push($listeners, ...$wildcard);
            }
        }

        return $listeners;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function classMethodOf(\Closure|string $listener): ?array
    {
        if (!$listener instanceof \Closure) {
            return null;
        }

        // A wildcard view name wraps the class callback once more, in `addEventListener()`.
        $wrapped = $this->capturedBy($listener, 'addEventListener') ?? [];

        if (isset($wrapped['callback']) && \count($wrapped) === 1) {
            $listener = $wrapped['callback'];
        }

        $captured = $this->capturedBy($listener, 'buildClassEventCallback') ?? [];

        if (!isset($captured['class'], $captured['method']) || \count($captured) !== 2) {
            return null;
        }

        // `make()` would resolve whatever the container maps the name to, not the class named here.
        $class = \ltrim($captured['class'], '\\');

        if ($this->app->bound($captured['class']) || $this->app->bound($class)) {
            return null;
        }

        return [$class, \strtolower($captured['method'])];
    }

    /**
     * The variables a closure captured, provided it was declared inside the named `Factory` method.
     * Only the closures `ManagesEvents` builds are ever declared there, and they capture exactly the
     * shapes below.
     *
     * @return array{class?: string, method?: string, callback?: \Closure}|null
     */
    private function capturedBy(\Closure $closure, string $factoryMethod): ?array
    {
        $function = new \ReflectionFunction($closure);
        $declaring = new \ReflectionMethod(Factory::class, $factoryMethod);

        if ($function->getFileName() !== $declaring->getFileName()
            || $function->getStartLine() < $declaring->getStartLine()
            || $function->getEndLine() > $declaring->getEndLine()
        ) {
            return null;
        }

        /** @var array{class?: string, method?: string, callback?: \Closure} */
        return $function->getStaticVariables();
    }
}
