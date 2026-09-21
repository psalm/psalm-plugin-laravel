<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Illuminate\View\Compilers\BladeCompiler;

/**
 * Describes every input `BladeCompiler` mixes into how it compiles a template, beyond the
 * template's own source: custom directives, `if()` conditions, extensions, precompilers, string
 * preparation callbacks, echo handlers/format, JSON encoding options, component tag maps, and
 * whether component tag compilation is even on. {@see ShadowManifest::fingerprint()} folds the
 * result in so an application that edits its own directives (or swaps a compiler subclass)
 * invalidates its shadow cache instead of reusing bytes compiled against a different environment.
 *
 * Two traps this specifically guards against, both in Laravel's own `BladeCompiler::if()`:
 * the user's condition callback lives in the private `$conditions` array, never in
 * `getCustomDirectives()` — hashing directives alone misses an edited condition entirely. And
 * `aliasComponent()` / `include()` / `aliasInclude()` register closures DEFINED INSIDE
 * `BladeCompiler.php` itself (an unchanging vendor file), distinguished from each other only by
 * their captured `use()` variables — hashing the file alone would collapse every alias to the
 * same descriptor.
 *
 * Every failure degrades to `trustworthy = false` rather than guessing: a callable this class
 * cannot resolve to a readable file (an internal function, an `eval()`'d closure) or a static
 * variable it cannot deterministically describe (an object, another closure) means the hash
 * cannot be trusted to invalidate correctly, so the caller is told to skip the freshness check
 * for this run instead of risking a stale shadow silently surviving forever. Never throws: a
 * `\Throwable` escaping into {@see BladeBootstrapper::boot()}'s catch would disable Blade
 * analysis for the whole run over what is, at worst, one uncacheable input.
 *
 * @internal
 */
final class CompilerEnvironment
{
    /**
     * @return array{0: string, 1: bool} the environment hash, and whether every input that fed it
     *         could be resolved deterministically
     */
    public static function describe(BladeCompiler $compiler): array
    {
        try {
            $trustworthy = true;
            /** @var array<string, string> $fileHashes path => sha1_file(), cached within this call */
            $fileHashes = [];
            $parts = [];

            $parts[] = 'class:' . self::describeCompilerClass($compiler, $trustworthy);
            $parts[] = self::describeCallableMap('customDirectives', $compiler->getCustomDirectives(), $fileHashes, $trustworthy);
            $parts[] = self::describeCallableMap('extensions', $compiler->getExtensions(), $fileHashes, $trustworthy);
            $parts[] = self::describeCallableMap('conditions', self::readArrayProperty($compiler, 'conditions', $trustworthy), $fileHashes, $trustworthy);
            $parts[] = self::describeCallableMap('precompilers', self::readArrayProperty($compiler, 'precompilers', $trustworthy), $fileHashes, $trustworthy);
            $parts[] = self::describeCallableMap('prepareStringsForCompilationUsing', self::readArrayProperty($compiler, 'prepareStringsForCompilationUsing', $trustworthy), $fileHashes, $trustworthy);
            $parts[] = self::describeCallableMap('echoHandlers', self::readArrayProperty($compiler, 'echoHandlers', $trustworthy), $fileHashes, $trustworthy);
            $parts[] = 'echoFormat:' . self::describeValue(self::readProperty($compiler, 'echoFormat', $trustworthy), $trustworthy);
            $parts[] = 'encodingOptions:' . self::describeValue(self::readProperty($compiler, 'encodingOptions', $trustworthy), $trustworthy);
            $parts[] = 'compilesComponentTags:' . self::describeValue(self::readProperty($compiler, 'compilesComponentTags', $trustworthy), $trustworthy);
            $parts[] = 'classComponentAliases:' . self::describeValue($compiler->getClassComponentAliases(), $trustworthy);
            $parts[] = 'classComponentNamespaces:' . self::describeValue($compiler->getClassComponentNamespaces(), $trustworthy);
            $parts[] = 'anonymousComponentPaths:' . self::describeValue($compiler->getAnonymousComponentPaths(), $trustworthy);
            $parts[] = 'anonymousComponentNamespaces:' . self::describeValue($compiler->getAnonymousComponentNamespaces(), $trustworthy);

            return [\hash('xxh128', \implode('|', $parts)), $trustworthy];
        } catch (\Throwable) {
            return ['', false];
        }
    }

    /** A non-framework compiler subclass contributes its own class name plus its own file's hash. */
    private static function describeCompilerClass(BladeCompiler $compiler, bool &$trustworthy): string
    {
        $class = $compiler::class;

        if ($class === BladeCompiler::class) {
            return $class;
        }

        try {
            $file = (new \ReflectionClass($compiler))->getFileName();
        } catch (\Throwable) {
            $file = false;
        }

        if (!\is_string($file)) {
            $trustworthy = false;

            return $class . ':unresolvable';
        }

        $hash = @\sha1_file($file);

        if ($hash === false) {
            $trustworthy = false;

            return $class . ':unresolvable';
        }

        return $class . ':' . $file . ':' . $hash;
    }

    /**
     * @param array<array-key, mixed> $map
     * @param array<string, string>   $fileHashes
     */
    private static function describeCallableMap(string $slot, array $map, array &$fileHashes, bool &$trustworthy): string
    {
        $parts = [];

        /** @psalm-suppress MixedAssignment untyped data straight from BladeCompiler's own untyped array properties */
        foreach ($map as $key => $callable) {
            $parts[] = $key . '=' . self::describeCallable($callable, $fileHashes, $trustworthy);
        }

        return $slot . ':[' . \implode(',', $parts) . ']';
    }

    /** @param array<string, string> $fileHashes */
    private static function describeCallable(mixed $callable, array &$fileHashes, bool &$trustworthy): string
    {
        if (!\is_callable($callable)) {
            $trustworthy = false;

            return 'unresolvable';
        }

        try {
            $closure = $callable instanceof \Closure ? $callable : \Closure::fromCallable($callable);
            $reflection = new \ReflectionFunction($closure);
        } catch (\Throwable) {
            $trustworthy = false;

            return 'unresolvable';
        }

        $file = $reflection->getFileName();

        // Internal functions return `false`; an `eval()`'d closure returns a string ending in
        // "eval()'d code" rather than a real path — neither can be hashed as a file.
        if ($file === false || \str_contains($file, "eval()'d code")) {
            $trustworthy = false;

            return 'unresolvable';
        }

        if (!isset($fileHashes[$file])) {
            $hash = @\sha1_file($file);

            if ($hash === false) {
                $trustworthy = false;

                return 'unresolvable';
            }

            $fileHashes[$file] = $hash;
        }

        return $file . ':' . $fileHashes[$file] . ':' . self::describeValue($reflection->getStaticVariables(), $trustworthy);
    }

    /**
     * Scalars and arrays of them serialize deterministically. Anything else captured in a
     * closure's `use()` clause (or found in a property this class reads via reflection) — an
     * object, another closure, a resource — cannot be described deterministically without
     * risking a false "unchanged" on a later run, so it flips `$trustworthy` instead of guessing.
     */
    private static function describeValue(mixed $value, bool &$trustworthy): string
    {
        if ($value === null || \is_scalar($value)) {
            return \get_debug_type($value) . ':' . (\is_string($value) ? $value : \var_export($value, true));
        }

        if (\is_array($value)) {
            $parts = [];

            /** @psalm-suppress MixedAssignment untyped data straight from BladeCompiler's own untyped array properties */
            foreach ($value as $key => $item) {
                $parts[] = $key . '=>' . self::describeValue($item, $trustworthy);
            }

            return '[' . \implode(',', $parts) . ']';
        }

        $trustworthy = false;

        return 'unresolvable:' . \get_debug_type($value);
    }

    /**
     * `new ReflectionProperty($object, $name)` cannot find a PRIVATE property declared on an
     * ancestor class when `$object` is an instance of a subclass — a documented PHP reflection
     * quirk, not a sign the property is absent (`encodingOptions` is `private`, declared on
     * `BladeCompiler` via `CompilesJson`, and every compiler *subclass* would otherwise flip
     * `$trustworthy` for no reason). Walking the hierarchy and reflecting via the exact class
     * that declares the property works around it.
     */
    private static function readProperty(object $object, string $property, bool &$trustworthy): mixed
    {
        for ($class = $object::class; $class !== false; $class = \get_parent_class($class)) {
            try {
                return (new \ReflectionProperty($class, $property))->getValue($object);
            } catch (\Throwable) {
                continue;
            }
        }

        $trustworthy = false;

        return null;
    }

    /** @return array<array-key, mixed> */
    private static function readArrayProperty(object $object, string $property, bool &$trustworthy): array
    {
        $value = self::readProperty($object, $property, $trustworthy);

        if (!\is_array($value)) {
            $trustworthy = false;

            return [];
        }

        return $value;
    }
}
