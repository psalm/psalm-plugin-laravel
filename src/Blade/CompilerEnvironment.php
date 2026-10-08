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
 *   any bound object other than the compiler being described itself, unless a closure's source
 *   provably never reaches it ({@see self::closureReachesThis()}).
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
 * Every failure degrades to an untrusted reason rather than guessing: a callable this class
 * cannot resolve to a readable file (an internal function, an `eval()`'d closure) or a static
 * variable it cannot deterministically describe (an object, another closure) means the hash
 * cannot be trusted to invalidate correctly, so the caller is told to skip the freshness check
 * for this run instead of risking a stale shadow silently surviving forever. Each reason names the
 * input and, when known, its source location, so the user can fix it. Never throws: a
 * `\Throwable` escaping into {@see BladeBootstrapper::boot()}'s catch would disable Blade
 * analysis for the whole run over what is, at worst, one uncacheable input.
 *
 * @internal
 */
final class CompilerEnvironment
{
    /** @var array<string, string> path => sha1_file() */
    private array $fileHashes = [];

    /** @var array<string, list<array{0: int|string, 1: string, 2: int}>|null> path => normalized tokens, null when unreadable */
    private array $fileTokens = [];

    /** @var list<string> */
    private array $reasons = [];

    /** @psalm-capabilities read-props */
    private function __construct(
        private readonly BladeCompiler $compiler,
        private readonly ?string $displayRoot,
    ) {}

    /**
     * @param ?string $displayRoot prefix stripped from paths in the reasons, for display only
     *
     * @return array{0: string, 1: list<string>} the environment hash, and why it cannot be
     *         trusted (empty when every input that fed it resolved deterministically)
     */
    public static function describe(BladeCompiler $compiler, ?string $displayRoot = null): array
    {
        try {
            $self = new self($compiler, $displayRoot);
            $hash = $self->hash();

            return [$hash, \array_values(\array_unique($self->reasons))];
        } catch (\Throwable $throwable) {
            return ['', ['the compiler environment could not be described: ' . $throwable->getMessage()]];
        }
    }

    private function hash(): string
    {
        $compiler = $this->compiler;

        $descriptor = [
            'class' => $this->describeCompilerClass(),
            'customDirectives' => $this->describeCallableMap($compiler->getCustomDirectives(), 'directive "%s"'),
            'extensions' => $this->describeCallableMap($compiler->getExtensions(), 'extension #%s'),
            'conditions' => $this->describeCallableMap($this->readArrayProperty('conditions'), 'condition "%s"'),
            'precompilers' => $this->describeCallableMap($this->readArrayProperty('precompilers'), 'precompiler #%s'),
            'prepareStringsForCompilationUsing' => $this->describeCallableMap($this->readArrayProperty('prepareStringsForCompilationUsing'), 'string preparation callback #%s'),
            'echoHandlers' => $this->describeCallableMap($this->readArrayProperty('echoHandlers'), 'echo handler for %s'),
            'echoFormat' => $this->describeValue($this->readProperty('echoFormat'), 'compiler property $echoFormat'),
            'encodingOptions' => $this->describeValue($this->readProperty('encodingOptions'), 'compiler property $encodingOptions'),
            'compilesComponentTags' => $this->describeValue($this->readProperty('compilesComponentTags'), 'compiler property $compilesComponentTags'),
            'classComponentAliases' => $this->describeValue($compiler->getClassComponentAliases(), 'class component aliases'),
            'classComponentNamespaces' => $this->describeValue($compiler->getClassComponentNamespaces(), 'class component namespaces'),
            'anonymousComponentPaths' => $this->describeValue($compiler->getAnonymousComponentPaths(), 'anonymous component paths'),
            'anonymousComponentNamespaces' => $this->describeValue($compiler->getAnonymousComponentNamespaces(), 'anonymous component namespaces'),
        ];

        return \hash('xxh128', \json_encode($descriptor, \JSON_THROW_ON_ERROR));
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
     * @psalm-capabilities read-props|write-this-props|write-refs
     */
    private function describeCompilerClass(): array
    {
        $compiler = $this->compiler;
        $class = $this->normalizeClassName($compiler::class);

        if ($compiler::class === BladeCompiler::class) {
            return ['class' => $class];
        }

        try {
            $file = (new \ReflectionClass($compiler))->getFileName();
        } catch (\Throwable) {
            $file = false;
        }

        $this->reasons[] = "compiler class {$class} is a BladeCompiler subclass, whose instance state cannot be fingerprinted"
            . (\is_string($file) ? ' (' . $this->displayPath($file) . ')' : '');

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
    private function normalizeClassName(string $class): string
    {
        return \preg_replace('/\$[0-9a-fA-F]+$/', '', $class) ?? $class;
    }

    /**
     * @param array<array-key, mixed> $map
     * @param string                  $label sprintf() format naming one entry by its key
     *
     * @return list<array{0: array-key, 1: array}> a LIST of `[key, description]` pairs rather than
     *         a re-keyed array: PHP would otherwise coalesce an int key and its string form
     *         (`5` and `'5'`) into the same slot, silently dropping one callable's descriptor.
     */
    private function describeCallableMap(array $map, string $label): array
    {
        $entries = [];

        // Keys, not values: a foreach over untyped compiler data binds a mixed local per element.
        foreach (\array_keys($map) as $key) {
            $entries[] = [$key, $this->describeCallable($map[$key], \sprintf($label, (string) $key))];
        }

        return $entries;
    }

    /**
     * `ReflectionFunction::getStaticVariables()` never exposes a closure's bound `$this` — an
     * invokable object (`Blade::directive('x', new SomeDirective)`), an array callable
     * (`[$service, 'compile']`), or any `Closure::bindTo($stateObject)` therefore contributes
     * NOTHING but its (shared, unchanging) class file to the hash, no matter what state the bound
     * object holds. Two exceptions stay trusted: `bindDirective()` binds to the `BladeCompiler`
     * being described, which carries no state beyond what the rest of this class hashes; and a
     * closure whose source never reaches its bound object ({@see self::closureReachesThis()}),
     * the shape of every non-static closure written in a ServiceProvider's `boot()`.
     *
     * The file hash alone also cannot tell two callables declared in the SAME file apart (e.g.
     * `Str::upper` versus `Str::lower`), so the reflected name, source line range, and declaring
     * scope are folded in alongside it.
     *
     * @return array{type: string, file?: string, hash?: string, name?: string, startLine?: int|false, endLine?: int|false, scope?: ?string, static?: array, boundClass?: string}
     */
    private function describeCallable(mixed $callable, string $label): array
    {
        if (!\is_callable($callable)) {
            $this->reasons[] = "{$label}: not callable (" . \get_debug_type($callable) . ')';

            return ['type' => 'unresolvable'];
        }

        try {
            $closure = $callable instanceof \Closure ? $callable : \Closure::fromCallable($callable);
            $reflection = new \ReflectionFunction($closure);
        } catch (\Throwable) {
            $this->reasons[] = "{$label}: callable cannot be reflected";

            return ['type' => 'unresolvable'];
        }

        $file = $reflection->getFileName();

        // Internal functions return `false`; an `eval()`'d closure returns a string ending in
        // "eval()'d code" rather than a real path — neither can be hashed as a file.
        if ($file === false || \str_contains($file, "eval()'d code")) {
            $this->reasons[] = $file === false
                ? "{$label}: internal function {$reflection->getName()}() has no source file to fingerprint"
                : "{$label}: callable declared in eval()'d code has no source file to fingerprint";

            return ['type' => 'unresolvable'];
        }

        $location = ' (' . $this->displayPath($file) . ':' . (int) $reflection->getStartLine() . ')';

        try {
            $scopeClass = $reflection->getClosureScopeClass();
        } catch (\Throwable) {
            $this->reasons[] = "{$label}: closure scope cannot be resolved{$location}";

            return ['type' => 'unresolvable'];
        }

        $boundThis = $reflection->getClosureThis();
        $boundClass = null;

        if ($boundThis !== null && $boundThis !== $this->compiler) {
            $boundClass = $this->normalizeClassName($boundThis::class);

            if (!$callable instanceof \Closure) {
                $this->reasons[] = \is_object($callable)
                    ? "{$label}: invokable {$boundClass} object, whose state cannot be fingerprinted{$location}"
                    : "{$label}: method of a {$boundClass} instance, whose state cannot be fingerprinted{$location}";

                return ['type' => 'unresolvable'];
            }

            if ($this->closureReachesThis($reflection, $file, $scopeClass, $boundThis)) {
                $this->reasons[] = "{$label}: closure bound to {$boundClass} can reach \$this{$location}";

                return ['type' => 'unresolvable'];
            }
        }

        if (!isset($this->fileHashes[$file])) {
            $hash = @\sha1_file($file);

            if ($hash === false) {
                $this->reasons[] = "{$label}: source file cannot be read{$location}";

                return ['type' => 'unresolvable'];
            }

            $this->fileHashes[$file] = $hash;
        }

        $scopeName = $scopeClass?->getName();

        // Same shape as describeValue() of the whole array, labelled per variable.
        $staticPairs = [];
        $staticVariables = $reflection->getStaticVariables();

        foreach (\array_keys($staticVariables) as $name) {
            $staticPairs[] = [$name, $this->describeValue($staticVariables[$name], "{$label}: captured variable \${$name}", $location)];
        }

        $description = [
            'type' => 'callable',
            'file' => $file,
            'hash' => $this->fileHashes[$file],
            'name' => $reflection->getName(),
            'startLine' => $reflection->getStartLine(),
            'endLine' => $reflection->getEndLine(),
            'scope' => $scopeName !== null ? $this->normalizeClassName($scopeName) : null,
            'static' => ['t' => 'array', 'v' => $staticPairs],
        ];

        if ($boundClass !== null) {
            // `static::` resolves against the bound object's class, not the declaring scope.
            $description['boundClass'] = $boundClass;
        }

        return $description;
    }

    /**
     * Sound because the bound object is reachable only through `$this` (directly, via a
     * variable-variable, or forwarded by an instance method called as `self::`/`static::`/
     * `parent::m()`); the declaring file, scope, and bound class are hashed, so `self::`/`static::`
     * constants and static members are already covered. Scans every token on the closure's lines,
     * so other code sharing those lines only makes this stricter.
     */
    private function closureReachesThis(\ReflectionFunction $reflection, string $file, ?\ReflectionClass $scope, object $boundThis): bool
    {
        $tokens = $this->tokens($file);
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();

        if ($tokens === null || $start === false || $end === false) {
            return true;
        }

        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            [$id, $text, $line] = $tokens[$i];

            if ($line < $start || $line > $end) {
                continue;
            }

            if ($id === \T_VARIABLE && $text === '$this') {
                return true;
            }

            // `$$name`, `${expr}`, `"${name}"`: the variable name is computed.
            if ($text === '$' || $id === \T_DOLLAR_OPEN_CURLY_BRACES) {
                return true;
            }

            $keyword = \strtolower($text);

            if (!\in_array($keyword, ['self', 'static', 'parent'], true)
                || ($tokens[$i + 1][1] ?? null) !== '::'
            ) {
                continue;
            }

            [$memberId, $member] = $tokens[$i + 2] ?? [null, ''];
            $next = $tokens[$i + 3][1] ?? '';

            // `self::{$m}()` / `self::$m()`: an unknown method, maybe non-static.
            if ($member === '{' || ($memberId === \T_VARIABLE && $next === '(')) {
                return true;
            }

            if ($memberId !== \T_STRING || $next !== '(') {
                continue; // constant, static property, `::class`
            }

            $class = match ($keyword) {
                'self' => $scope,
                // Bound class may differ from the scope, and its file is not hashed.
                'static' => $boundThis::class === $scope?->getName() ? $scope : null,
                default => $scope?->getParentClass() ?: null,
            };

            if ($class === null || !$class->hasMethod($member) || !$class->getMethod($member)->isStatic()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whitespace and comments dropped, so a lookahead of one token is the next meaningful one.
     *
     * @return list<array{0: int|string, 1: string, 2: int}>|null
     */
    private function tokens(string $file): ?array
    {
        if (\array_key_exists($file, $this->fileTokens)) {
            return $this->fileTokens[$file];
        }

        $source = @\file_get_contents($file);
        $tokens = null;

        if ($source !== false) {
            $tokens = [];
            $line = 1;

            foreach (\token_get_all($source) as $token) {
                if (\is_array($token)) {
                    $line = $token[2];

                    if (!\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                        $tokens[] = [$token[0], $token[1], $line];
                    }

                    $line += \substr_count($token[1], "\n");

                    continue;
                }

                $tokens[] = [$token, $token, $line];
            }
        }

        return $this->fileTokens[$file] = $tokens;
    }

    /** @psalm-capabilities read-props */
    private function displayPath(string $path): string
    {
        $root = $this->displayRoot;

        if ($root === null || $root === '') {
            return $path;
        }

        $root = \rtrim($root, '/\\') . \DIRECTORY_SEPARATOR;

        return \str_starts_with($path, $root) ? \substr($path, \strlen($root)) : $path;
    }

    /**
     * Every leaf is tagged with its PHP type and returned as a native, JSON-safe structure, never
     * a hand-joined string: two differently-shaped inputs (an object, versus a string crafted to
     * equal another entry's flattened text) could otherwise describe identically. Arrays are
     * encoded as a LIST of `[key, value]` pairs, immune to the same int-vs-string key coalescing
     * {@see self::describeCallableMap()} avoids. Anything not JSON-representable on its own terms
     * (an object, another closure, a resource) records a reason instead of guessing at a
     * description for it.
     *
     * @return array{t: string, v: mixed}
     *
     * @psalm-capabilities read-props|write-this-props|write-refs
     */
    private function describeValue(mixed $value, string $label, string $location = ''): array
    {
        if ($value === null || \is_scalar($value)) {
            return ['t' => \get_debug_type($value), 'v' => $value];
        }

        if (\is_array($value)) {
            $pairs = [];

            foreach (\array_keys($value) as $key) {
                $pairs[] = [$key, $this->describeValue($value[$key], $label, $location)];
            }

            return ['t' => 'array', 'v' => $pairs];
        }

        $this->reasons[] = "{$label} holds " . (\is_object($value) ? 'an object' : 'a value') . ' of type ' . \get_debug_type($value) . $location;

        return ['t' => 'unresolvable', 'v' => \get_debug_type($value)];
    }

    /**
     * `new ReflectionProperty($object, $name)` cannot find a PRIVATE property declared on an
     * ancestor class when `$object` is an instance of a subclass — a documented PHP reflection
     * quirk, not a sign the property is absent (`encodingOptions` is `private`, declared on
     * `BladeCompiler` via `CompilesJson`, and every compiler *subclass* would otherwise be
     * distrusted for no reason). Walking the hierarchy and reflecting via the exact class that
     * declares the property works around it.
     */
    private function readProperty(string $property): mixed
    {
        $object = $this->compiler;

        for ($class = $object::class; $class !== false; $class = \get_parent_class($class)) {
            try {
                return (new \ReflectionProperty($class, $property))->getValue($object);
            } catch (\Throwable) {
                continue;
            }
        }

        $this->reasons[] = "compiler property \${$property} cannot be read";

        return null;
    }

    /** @return array<array-key, mixed> */
    private function readArrayProperty(string $property): array
    {
        $before = \count($this->reasons);
        $value = $this->readProperty($property);

        if (!\is_array($value)) {
            if (\count($this->reasons) === $before) {
                $this->reasons[] = "compiler property \${$property} is not an array";
            }

            return [];
        }

        return $value;
    }
}
