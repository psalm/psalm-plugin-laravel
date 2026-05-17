<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application as LaravelApplication;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Snapshot of container accessor → concrete class from `$app->getBindings()`,
 * used to resolve vendor facades whose accessor is a string alias bound by a
 * service provider (issue #942) without invoking the runtime `Facade::getFacadeRoot()`
 * probe, which throws on constructors with un-bound deps.
 *
 * Known limitations:
 *  - Factory closure with branching (`fn () => $cond ? new A : new B`) — only the
 *    first `new` is taken.
 *  - Instance bindings (`Container::instance($abstract, $obj)`) live in `$instances`,
 *    not `$bindings`, and are not snapshotted.
 *
 * @internal
 */
final class ContainerBindingMapProvider
{
    /** @var array<string, class-string> */
    private static array $map = [];

    /**
     * Build the map. Idempotent — replaces the map on each call so test fixtures
     * registering extra providers (`tests/Type/macro-fixtures.php`) can refresh
     * the snapshot.
     */
    public static function init(): void
    {
        self::$map = [];

        $app = ApplicationProvider::getApp();

        // Container::bind() always wraps non-Closure concretes via getClosure()
        // before storing, so `concrete` is always Closure.
        /** @var array<string, array{concrete: \Closure, shared: bool}> $bindings */
        $bindings = $app->getBindings();

        foreach ($bindings as $accessor => $binding) {
            if ($accessor === '') {
                continue;
            }

            $class = self::introspectClosure($binding['concrete']);
            if ($class !== null) {
                self::$map[$accessor] = $class;
            }
        }

        // Container::alias($abstract, $alias) stores aliases[$alias] = $abstract;
        // facade accessors are often the alias, the bindings map holds the abstract.
        foreach (self::readAliases($app) as $alias => $abstract) {
            if (isset(self::$map[$alias])) {
                continue;
            }

            if (isset(self::$map[$abstract])) {
                self::$map[$alias] = self::$map[$abstract];
            } elseif (\class_exists($abstract)) {
                // alias(X::class, 'short') with no explicit bind — abstract IS the class.
                self::$map[$alias] = $abstract;
            }
        }
    }

    /**
     * @return ?class-string
     * @psalm-external-mutation-free
     */
    public static function getClass(string $accessor): ?string
    {
        return self::$map[$accessor] ?? null;
    }

    /** @return ?class-string */
    private static function introspectClosure(\Closure $closure): ?string
    {
        try {
            $ref = new \ReflectionFunction($closure);
        } catch (\Throwable) {
            return null;
        }

        // Path 1 — class-string concrete: Container::getClosure() wraps with
        // `use ($abstract, $concrete)`, so $concrete is in static vars.
        /** @var mixed $concrete */
        $concrete = $ref->getStaticVariables()['concrete'] ?? null;
        if (\is_string($concrete) && $concrete !== '' && \class_exists($concrete)) {
            return $concrete;
        }

        // Path 2 — user factory closure: parse the host file for a `new X(...)`
        // inside the closure's line range.
        $file = $ref->getFileName();
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();
        if ($file === false || $start === false || $end === false) {
            return null;
        }

        return self::findReturnedClassInRange($file, $start, $end);
    }

    /** @return ?class-string */
    private static function findReturnedClassInRange(string $file, int $start, int $end): ?string
    {
        $stmts = self::parsedFile($file);
        if ($stmts === null) {
            return null;
        }

        $newNodes = (new NodeFinder())->find($stmts, static function (Node $node) use ($start, $end): bool {
            return $node instanceof Node\Expr\New_
                && $node->class instanceof Node\Name
                && $node->getStartLine() >= $start
                && $node->getEndLine() <= $end;
        });

        foreach ($newNodes as $node) {
            \assert($node instanceof Node\Expr\New_);
            \assert($node->class instanceof Node\Name);
            $class = $node->class->toString();
            if (\class_exists($class)) {
                /** @var class-string $class */
                return $class;
            }
        }

        return null;
    }

    /** @return ?list<Node\Stmt> */
    private static function parsedFile(string $file): ?array
    {
        try {
            $contents = @\file_get_contents($file);
            if ($contents === false) {
                return null;
            }

            $stmts = (new ParserFactory())->createForHostVersion()->parse($contents);
            if ($stmts === null) {
                return null;
            }

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            /** @var list<Node\Stmt> $resolved */
            $resolved = $traverser->traverse($stmts);

            return $resolved;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, string> alias → abstract; protected, no public getter */
    private static function readAliases(LaravelApplication $app): array
    {
        try {
            /** @psalm-var mixed $value */
            $value = (new \ReflectionProperty(Container::class, 'aliases'))->getValue($app);
            if (!\is_array($value)) {
                return [];
            }

            /** @var array<string, string> $value */
            return $value;
        } catch (\Throwable) {
            return [];
        }
    }
}
