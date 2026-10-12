<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Illuminate\Container\Container;
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
 * binds, extends, hooks before resolving or binds through an attribute, or a dispatcher or factory
 * other than Laravel's, cannot be read, so the view's sources are unknown.
 *
 * @internal
 */
final class ComposerSourceReader
{
    private const BINDING_ATTRIBUTES = ['illuminate\\container\\attributes\\bind', 'illuminate\\container\\attributes\\bindwhen'];

    /** @psalm-mutation-free */
    public function __construct(
        private readonly ?Factory $factory,
    ) {}

    /**
     * @return list<array{0: string, 1: string}>|null class and lowercased method of every composer
     *         and creator reaching the view; empty when none does, null when one is unreadable
     */
    public function sourcesFor(string $viewName): ?array
    {
        $factory = $this->factory;
        $events = $factory?->getDispatcher();

        if (!$factory instanceof \Illuminate\View\Factory || $events === null) {
            return [];
        }

        try {
            // Anything but Laravel's own classes can add listeners or behaviour the raw listener list
            // does not show, so only the fact that a listener exists is known.
            if ($events::class !== Dispatcher::class || $this->overridesDispatch($factory)) {
                return $events->hasListeners("composing: {$viewName}") || $events->hasListeners("creating: {$viewName}") ? null : [];
            }

            $sources = [];

            foreach (["composing: {$viewName}", "creating: {$viewName}"] as $event) {
                foreach ($this->listenersFor($events, $event) as [$listener, $wildcard]) {
                    $source = $this->classMethodOf($listener, $wildcard);

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

    private function overridesDispatch(Factory $factory): bool
    {
        // `viewInstance()` builds the view every composer receives, and a View subclass's `with()` can
        // add keys of its own.
        foreach (['callComposer', 'callCreator', 'viewInstance'] as $method) {
            if ((new \ReflectionMethod($factory, $method))->getDeclaringClass()->getName() !== Factory::class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{0: \Closure|string, 1: bool}> listener and whether a wildcard key holds it
     */
    private function listenersFor(Dispatcher $events, string $event): array
    {
        /** @var array<string, list<\Closure|string>> $raw */
        $raw = $events->getRawListeners();
        $listeners = \array_map(static fn(\Closure|string $listener): array => [$listener, false], $raw[$event] ?? []);

        // The same test `Dispatcher::hasWildcardListeners()` applies; `$wildcards` has no getter.
        /** @var array<string, list<\Closure|string>> $wildcards */
        $wildcards = (new \ReflectionProperty(Dispatcher::class, 'wildcards'))->getValue($events);

        foreach ($wildcards as $pattern => $wildcard) {
            if (Str::is($pattern, $event)) {
                foreach ($wildcard as $listener) {
                    $listeners[] = [$listener, true];
                }
            }
        }

        return $listeners;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function classMethodOf(\Closure|string $listener, bool $wildcard): ?array
    {
        if (!$listener instanceof \Closure) {
            return null;
        }

        // A wildcard view name wraps the class callback once more, in `addEventListener()`, which
        // turns the dispatcher's `(event, payload)` into the view. The same class callback registered
        // raw under a wildcard key would read the event name instead, and an exact key never holds
        // the wrapper.
        $wrapped = $this->capturedBy($listener, 'addEventListener') ?? [];

        $unwrapped = isset($wrapped['callback']) && \count($wrapped) === 1 ? $wrapped['callback'] : null;

        if ($wildcard !== ($unwrapped !== null)) {
            return null;
        }

        $listener = $unwrapped ?? $listener;

        $captured = $this->capturedBy($listener, 'buildClassEventCallback') ?? [];

        if (!isset($captured['class'], $captured['method']) || \count($captured) !== 2) {
            return null;
        }

        // `make()` would resolve whatever the factory's own container maps the name to, not the class
        // named here.
        $class = \ltrim($captured['class'], '\\');
        $container = $this->factory?->getContainer();

        if (!$container instanceof Container
            || $container->bound($captured['class']) || $container->bound($class)
            || $this->extended($container, $captured['class']) || $this->extended($container, $class)
            || $this->hookedBeforeResolving($container, $captured['class']) || $this->hookedBeforeResolving($container, $class)
            || $this->boundByAttribute($class)
        ) {
            return null;
        }

        return [$class, \strtolower($captured['method'])];
    }

    /** `Container::getExtenders()` is protected, and extenders rewrite the object `make()` returns. */
    private function extended(Container $container, string $class): bool
    {
        return (new \ReflectionMethod($container, 'getExtenders'))->invoke($container, $class) !== [];
    }

    /**
     * `Container::resolve()` fires before-resolving callbacks ahead of picking the concrete, and one can
     * bind the class to another. Per-type entries match as `fireBeforeResolvingCallbacks()` matches them;
     * global ones fire for every class and decline every composer, but the framework registers none.
     */
    private function hookedBeforeResolving(Container $container, string $class): bool
    {
        if ((new \ReflectionProperty(Container::class, 'globalBeforeResolvingCallbacks'))->getValue($container) !== []) {
            return true;
        }

        // Keyed by abstract, which `is_subclass_of()` takes as a class name the way Laravel passes it.
        /** @var array<class-string, mixed> $callbacks */
        $callbacks = (new \ReflectionProperty(Container::class, 'beforeResolvingCallbacks'))->getValue($container);

        foreach (\array_keys($callbacks) as $type) {
            if ($type === $class || \is_subclass_of($class, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `Container::getConcrete()` binds a class carrying `Bind` or `BindWhen` lazily, on first resolution,
     * so `bound()` misses it until then. Matched by name, since older Laravel lacks the attribute classes;
     * only the class's own attributes count, as `ReflectionClass::getAttributes()` reads no parents.
     */
    private function boundByAttribute(string $class): bool
    {
        if (!\class_exists($class)) {
            return false;
        }

        foreach ((new \ReflectionClass($class))->getAttributes() as $attribute) {
            if (\in_array(\strtolower($attribute->getName()), self::BINDING_ATTRIBUTES, true)) {
                return true;
            }
        }

        return false;
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

        // The dispatcher can be shared: a closure another factory built resolves through that
        // factory's container, not the one read here.
        if ($function->getClosureThis() !== $this->factory
            || $function->getFileName() !== $declaring->getFileName()
            || $function->getStartLine() < $declaring->getStartLine()
            || $function->getEndLine() > $declaring->getEndLine()
        ) {
            return null;
        }

        /** @var array{class?: string, method?: string, callback?: \Closure} */
        return $function->getStaticVariables();
    }
}
