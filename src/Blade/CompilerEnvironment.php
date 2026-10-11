<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;

/**
 * Describes every input `BladeCompiler` mixes into how it compiles a template, beyond the
 * template's own source: custom directives, `if()` conditions, extensions, precompilers, string
 * preparation callbacks, echo handlers/format, JSON encoding options, component tag maps, and
 * whether component tag compilation is even on. {@see ShadowManifest::fingerprint()} folds the
 * result in so an application that edits its own directives (or swaps a compiler subclass)
 * invalidates its shadow cache instead of reusing bytes compiled against a different environment.
 *
 * The whole descriptor is a single nested, JSON-safe structure (scalars tagged with their PHP
 * type, arrays represented as a LIST of `[key, value]` pairs) hashed with exactly one
 * `json_encode()` call at the end, never hand-joined strings: two differently-shaped inputs (an
 * object versus a string crafted to equal another entry's flattened text) could otherwise describe
 * identically, letting an untrusted run's compiled bytes later be reused as a "trusted" hit for a
 * genuinely different environment.
 *
 * Traps this specifically guards against:
 * - Both `getCustomDirectives()` alone and the callable's file hash alone miss real changes.
 *   Laravel's own `BladeCompiler::if()` stores the user's condition callback in the private
 *   `$conditions` array, never in `getCustomDirectives()`. And `aliasComponent()` / `include()` /
 *   `aliasInclude()` register closures DEFINED INSIDE `BladeCompiler.php` itself (an unchanging
 *   vendor file), distinguished from each other only by their captured `use()` variables.
 * - `ReflectionFunction::getStaticVariables()` never exposes a closure's bound `$this`, so an
 *   invokable object or array callable (`[$service, 'compile']`) contributes nothing but its class
 *   file, no matter what state the bound object holds — {@see self::describeCallable()} distrusts
 *   any bound object other than the compiler being described itself or a `ServiceProvider`.
 * - The file hash alone cannot tell two callables declared in the SAME file apart (e.g. selecting
 *   `Str::upper` versus `Str::lower`) — the reflected name, source line range, and declaring scope
 *   are folded in too.
 * - A compiler SUBCLASS can carry constructor-injected or otherwise-set instance state this class
 *   has no way to enumerate ({@see \Tests\Psalm\LaravelPlugin\Unit\Blade\ThrowingBladeCompiler}'s
 *   `$needle` is exactly this shape), so any subclass is unconditionally untrustworthy, never just
 *   when its file cannot be hashed. Its class name and file hash are still contributed so the
 *   descriptor stays stable across two runs of the SAME subclass source.
 * - An anonymous class's name carries a per-process ordinal suffix (`class@anonymous/path.php:12$5`)
 *   that shifts whenever an unrelated anonymous class loads earlier, with nothing about the
 *   compiler itself changing — {@see self::normalizeClassName()} strips it.
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
     * @return array{0: string, 1: bool, 2: list<string>} the environment hash, whether every input
     *         that fed it could be resolved deterministically, and the callable inputs that could not
     *         (e.g. `directive 'ray' (closure bound to Foo)`), for the user-facing warning
     */
    public static function describe(BladeCompiler $compiler): array
    {
        try {
            $trustworthy = true;
            /** @var array<string, string> $fileHashes path => sha1_file(), cached within this call */
            $fileHashes = [];
            /** @var list<string> $reasons */
            $reasons = [];

            $descriptor = [
                'class' => self::describeCompilerClass($compiler, $trustworthy),
                'customDirectives' => self::describeCallableMap($compiler->getCustomDirectives(), 'directive', $compiler, $fileHashes, $trustworthy, $reasons),
                'extensions' => self::describeCallableMap($compiler->getExtensions(), 'extension', $compiler, $fileHashes, $trustworthy, $reasons),
                'conditions' => self::describeCallableMap(self::readArrayProperty($compiler, 'conditions', $trustworthy), 'condition', $compiler, $fileHashes, $trustworthy, $reasons),
                'precompilers' => self::describeCallableMap(self::readArrayProperty($compiler, 'precompilers', $trustworthy), 'precompiler', $compiler, $fileHashes, $trustworthy, $reasons),
                'prepareStringsForCompilationUsing' => self::describeCallableMap(self::readArrayProperty($compiler, 'prepareStringsForCompilationUsing', $trustworthy), 'string preparation callback', $compiler, $fileHashes, $trustworthy, $reasons),
                'echoHandlers' => self::describeCallableMap(self::readArrayProperty($compiler, 'echoHandlers', $trustworthy), 'echo handler', $compiler, $fileHashes, $trustworthy, $reasons),
                'echoFormat' => self::describeValue(self::readProperty($compiler, 'echoFormat', $trustworthy), $trustworthy),
                'encodingOptions' => self::describeValue(self::readProperty($compiler, 'encodingOptions', $trustworthy), $trustworthy),
                'compilesComponentTags' => self::describeValue(self::readProperty($compiler, 'compilesComponentTags', $trustworthy), $trustworthy),
                'classComponentAliases' => self::describeValue($compiler->getClassComponentAliases(), $trustworthy),
                'classComponentNamespaces' => self::describeValue($compiler->getClassComponentNamespaces(), $trustworthy),
                'anonymousComponentPaths' => self::describeValue($compiler->getAnonymousComponentPaths(), $trustworthy),
                'anonymousComponentNamespaces' => self::describeValue($compiler->getAnonymousComponentNamespaces(), $trustworthy),
            ];

            return [\hash('xxh128', \json_encode($descriptor, \JSON_THROW_ON_ERROR)), $trustworthy, $reasons];
        } catch (\Throwable) {
            return ['', false, []];
        }
    }

    /**
     * Any compiler subclass — even one that overrides nothing itself — can carry
     * constructor-injected or otherwise-set instance state this class has no way to enumerate, so
     * it is unconditionally untrustworthy, never just when its file cannot be hashed. The class
     * name and file hash are still contributed so the descriptor stays deterministic across two
     * runs of the SAME subclass source.
     *
     * @return array{class: string, file?: string, hash?: string}
     *
     * @psalm-external-mutation-free
     */
    private static function describeCompilerClass(BladeCompiler $compiler, bool &$trustworthy): array
    {
        $class = self::normalizeClassName($compiler::class);

        if ($compiler::class === BladeCompiler::class) {
            return ['class' => $class];
        }

        $trustworthy = false;

        try {
            $file = (new \ReflectionClass($compiler))->getFileName();
        } catch (\Throwable) {
            $file = false;
        }

        if (!\is_string($file)) {
            return ['class' => $class];
        }

        $hash = @\sha1_file($file);

        if ($hash === false) {
            return ['class' => $class];
        }

        return ['class' => $class, 'file' => $file, 'hash' => $hash];
    }

    /**
     * Anonymous classes get a per-process ordinal suffix (`class@anonymous/path/to/file.php:12$5`)
     * that is NOT stable across processes even for byte-identical source: loading one extra
     * anonymous class earlier shifts it, with nothing about the compiler itself changing. The
     * ordinal is rendered in HEXADECIMAL (`$359`, `$35d`, ...), not decimal — a suite with enough
     * anonymous classes reaches letters a-f, at which point a decimal-only pattern silently stops
     * matching and the "stable" suffix strip does nothing. Stripping the trailing
     * `$<hex digits>` leaves the file:line portion, which IS stable. A normal class name never
     * ends in `$<hex digits>` (`$` is not a valid identifier character), so this is safe to apply
     * unconditionally.
     *
     * @psalm-pure
     */
    private static function normalizeClassName(string $class): string
    {
        return \preg_replace('/\$[0-9a-fA-F]+$/', '', $class) ?? $class;
    }

    /**
     * @param array<array-key, mixed> $map
     * @param array<string, string>   $fileHashes
     * @param list<string>            $reasons    appended with one entry per entry of `$map` that cannot be trusted
     *
     * @return list<array{0: array-key, 1: array}> a LIST of `[key, description]` pairs rather than
     *         a re-keyed array: PHP would otherwise coalesce an int key and its string form
     *         (`5` and `'5'`) into the same slot, silently dropping one callable's descriptor.
     */
    private static function describeCallableMap(array $map, string $kind, BladeCompiler $compiler, array &$fileHashes, bool &$trustworthy, array &$reasons): array
    {
        $entries = [];

        // Keys, not values: a foreach over untyped compiler data binds a mixed local per element.
        foreach (\array_keys($map) as $key) {
            $distrust = null;
            $entries[] = [$key, self::describeCallable($map[$key], $compiler, $fileHashes, $distrust)];

            if ($distrust !== null) {
                $trustworthy = false;
                $reasons[] = "{$kind} '{$key}' ({$distrust})";
            }
        }

        return $entries;
    }

    /**
     * `ReflectionFunction::getStaticVariables()` never exposes a closure's bound `$this` — an
     * invokable object (`Blade::directive('x', new SomeDirective)`), an array callable
     * (`[$service, 'compile']`), or any `Closure::bindTo($stateObject)` therefore contributes
     * NOTHING but its (shared, unchanging) class file to the hash, no matter what state the bound
     * object holds. `bindDirective()` is the one exception: it always binds to the `BladeCompiler`
     * instance being described (`BladeCompiler::directive($name, $handler, bind: true)`), which
     * carries no state of its own beyond what the rest of this class already hashes, so that case
     * alone stays trusted. A closure bound to a `ServiceProvider` (a plain `Blade::directive()` /
     * `Blade::if()` closure written in a provider's `boot()`, as laravel/pennant and
     * spatie/laravel-ray do) is trusted too: its file (the provider) is hashed, and provider
     * instance state read while compiling is accepted as unfingerprinted, the same trade
     * documented for runtime `config()` reads in docs/blade.md.
     *
     * The file hash alone also cannot tell two callables declared in the SAME file apart (e.g.
     * `Str::upper` versus `Str::lower`), so the reflected name, source line range, and declaring
     * scope are folded in alongside it.
     *
     * @param array<string, string> $fileHashes
     * @param ?string               $distrust   set to why the callable cannot be trusted, when it cannot
     *
     * @return array{type: string, file?: string, hash?: string, name?: string, startLine?: int|false, endLine?: int|false, scope?: ?string, static?: array}
     */
    private static function describeCallable(mixed $callable, BladeCompiler $compiler, array &$fileHashes, ?string &$distrust): array
    {
        if (!\is_callable($callable)) {
            $distrust = 'not callable';

            return ['type' => 'unresolvable'];
        }

        try {
            $closure = $callable instanceof \Closure ? $callable : \Closure::fromCallable($callable);
            $reflection = new \ReflectionFunction($closure);
        } catch (\Throwable) {
            $distrust = 'cannot be reflected';

            return ['type' => 'unresolvable'];
        }

        $boundThis = $reflection->getClosureThis();

        if ($boundThis !== null && $boundThis !== $compiler && !$boundThis instanceof ServiceProvider) {
            $distrust = ($callable instanceof \Closure ? 'closure' : 'callable') . ' bound to ' . self::normalizeClassName($boundThis::class);

            return ['type' => 'unresolvable'];
        }

        $file = $reflection->getFileName();

        // Internal functions return `false`; an `eval()`'d closure returns a string ending in
        // "eval()'d code" rather than a real path — neither can be hashed as a file.
        if ($file === false || \str_contains($file, "eval()'d code")) {
            $distrust = "internal function or eval()'d code";

            return ['type' => 'unresolvable'];
        }

        if (!isset($fileHashes[$file])) {
            $hash = @\sha1_file($file);

            if ($hash === false) {
                $distrust = 'source file unreadable';

                return ['type' => 'unresolvable'];
            }

            $fileHashes[$file] = $hash;
        }

        try {
            $scopeClass = $reflection->getClosureScopeClass()?->getName();
        } catch (\Throwable) {
            $distrust = 'scope class unresolvable';

            return ['type' => 'unresolvable'];
        }

        $staticVariablesTrusted = true;
        $static = self::describeValue($reflection->getStaticVariables(), $staticVariablesTrusted);

        if (!$staticVariablesTrusted) {
            $distrust = 'captures an object or closure with use()';
        }

        return [
            'type' => 'callable',
            'file' => $file,
            'hash' => $fileHashes[$file],
            'name' => $reflection->getName(),
            'startLine' => $reflection->getStartLine(),
            'endLine' => $reflection->getEndLine(),
            'scope' => $scopeClass !== null ? self::normalizeClassName($scopeClass) : null,
            'static' => $static,
        ];
    }

    /**
     * Every leaf is tagged with its PHP type and returned as a native, JSON-safe structure, never
     * a hand-joined string: two differently-shaped inputs (an object, versus a string crafted to
     * equal another entry's flattened text) could otherwise describe identically. Arrays are
     * encoded as a LIST of `[key, value]` pairs, immune to the same int-vs-string key coalescing
     * {@see self::describeCallableMap()} avoids. Anything not JSON-representable on its own terms
     * (an object, another closure, a resource) flips `$trustworthy` instead of guessing at a
     * description for it.
     *
     * @return array{t: string, v: mixed}
     *
     * @psalm-external-mutation-free
     */
    private static function describeValue(mixed $value, bool &$trustworthy): array
    {
        if ($value === null || \is_scalar($value)) {
            return ['t' => \get_debug_type($value), 'v' => $value];
        }

        if (\is_array($value)) {
            $pairs = [];

            foreach (\array_keys($value) as $key) {
                $pairs[] = [$key, self::describeValue($value[$key], $trustworthy)];
            }

            return ['t' => 'array', 'v' => $pairs];
        }

        $trustworthy = false;

        return ['t' => 'unresolvable', 'v' => \get_debug_type($value)];
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
