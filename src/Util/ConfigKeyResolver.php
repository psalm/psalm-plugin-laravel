<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psalm\LaravelPlugin\Providers\ConfigRepositoryProvider;
use Psalm\Type;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TLiteralFloat;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TTrue;
use Psalm\Type\Union;

/**
 * Resolves dot-notation config keys against the booted Laravel app and returns a
 * Psalm Union describing the value's runtime type (generalized — see
 * {@see ConfigValueReflector}).
 *
 * Instance-based with a process-wide singleton to mirror
 * {@see \Psalm\LaravelPlugin\Handlers\Auth\AuthConfigAnalyzer}: callers in the
 * analysis hot path go through {@see instance()}, while unit tests construct
 * directly against a fake {@see ConfigRepository}.
 *
 * The cache distinguishes three states via `array_key_exists`:
 *   - key absent from the cache → not yet computed
 *   - cached value `null`       → known absent from config (Repository::has() === false)
 *   - cached value `Union`      → present (may be a Union of `null` if the config
 *                                 value is literally null)
 *
 * Throwing lookups (Repository binding unbound, partial bootstrap, exploding
 * service provider during has()/get()) are swallowed and cached as
 * {@see Type::getMixed()} so subsequent call-sites short-circuit and stay at a
 * hashmap lookup.
 *
 * **Repository immutability assumption.** The cache trusts that the booted
 * Repository's contents are stable for the lifetime of the Psalm process.
 * Laravel's package boot calls `mergeConfigFrom` once before analysis starts;
 * `Repository::set` / `prepend` / `push` are not invoked by user code between
 * the plugin's boot and the analysis run, so per-key reflection is safe to
 * memoise. Re-warm only via {@see reset()} (tests only).
 *
 * @internal
 */
final class ConfigKeyResolver
{
    private static ?ConfigKeyResolver $instance = null;

    /**
     * @var array<string, Union|null>
     */
    private array $cache = [];

    /** @psalm-mutation-free */
    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * Singleton accessor. Swallows {@see ConfigRepositoryProvider::get()} throws
     * the same way {@see warm()} does — if the container binding is missing or
     * a service provider explodes during plugin boot, the resolver caches a
     * no-op repository so subsequent call-sites short-circuit to `mixed` via
     * the cache miss → throw path. Letting the exception propagate would crash
     * Psalm at every `config()` / `Repository::get()` site, since plugin hook
     * handlers run outside {@see \Psalm\LaravelPlugin\Plugin::__invoke}'s
     * try/catch.
     */
    public static function instance(): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        try {
            return self::$instance = new self(ConfigRepositoryProvider::get());
        } catch (\Throwable) {
            return self::$instance = new self(new ThrowingConfigRepository());
        }
    }

    /**
     * Drops the singleton. Test-only — production analysis never invalidates
     * because the booted Repository is immutable for the Psalm process lifetime.
     *
     * @psalm-api
     * @psalm-external-mutation-free
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Resolve the full call-site return type for `config($key, $default)` or
     * `Repository::get($key, $default)`. Combines reflection with default-arg
     * merge logic:
     *
     *  - Key absent from config       → generalized default (no null union)
     *  - Key present, value not null  → reflected value (default ignored)
     *  - Key present, value is null   → null | generalized default
     *
     * Pass `$defaultType` = `Type::getNull()` when no default arg is supplied
     * (Laravel's signature defaults to null). Pass `Type::getMixed()` when the
     * default arg's type cannot be read from the call site.
     */
    public function resolveCallReturnType(string $key, Union $defaultType): Union
    {
        $this->warm($key);

        $reflected = $this->cache[$key];

        if ($reflected === null) {
            // Key absent: Laravel returns the default verbatim. Generalize so
            // `'fallback'` literals don't trigger spurious `===` warnings later.
            return self::generalizeDefault($defaultType);
        }

        if (!$reflected->isNullable()) {
            return $reflected;
        }

        // Reflected type carries TNull — merge with the generalized default so
        // callers that pass a default see `null | T` where T is the default's
        // generalized form.
        return Type::combineUnionTypes($reflected, self::generalizeDefault($defaultType));
    }

    /**
     * Single entry point used by both `config()` and `Repository::get()`
     * handlers. Extracts a literal string key from the first arg, reads the
     * default arg's type from the second slot, and routes through
     * {@see resolveCallReturnType}. Returns null when the call shape is not
     * narrowable (no args, dynamic key, array first-arg) so the caller defers
     * to the stub.
     *
     * @param list<\PhpParser\Node\Arg> $call_args
     */
    public static function resolveFromCallArgs(array $call_args, \Psalm\NodeTypeProvider $nodeTypeProvider): ?Union
    {
        $key = self::extractLiteralKey($call_args, $nodeTypeProvider);

        if ($key === null || $key === false) {
            return null;
        }

        return self::instance()->resolveCallReturnType(
            $key,
            self::readDefaultTypeAt($call_args, 1, $nodeTypeProvider),
        );
    }

    /**
     * Extract a literal string key from the first argument of a `config()` /
     * `Repository::get()` call. Inspects the raw AST first ({@see String_})
     * because {@see \Psalm\NodeTypeProvider} returns null inside Psalm's
     * `__callStatic` dispatch for Facade methods — the analyzer fires the
     * return-type-provider hook before resolving argument types. Falls back to
     * NodeTypeProvider for non-literal expressions where the type system has
     * already inferred a single-string-literal union.
     *
     * Returns:
     *   - the literal string when the key is statically resolvable
     *   - `false` when the first arg is an array literal (setter / multi-key
     *     form — caller must defer to the stub)
     *   - `null` when the key cannot be resolved (dynamic input or unknown
     *     type — caller must defer to mixed)
     *
     * @param list<\PhpParser\Node\Arg> $call_args
     */
    private static function extractLiteralKey(array $call_args, \Psalm\NodeTypeProvider $nodeTypeProvider): string|false|null
    {
        if ($call_args === []) {
            return null;
        }

        $expr = $call_args[0]->value;

        if ($expr instanceof \PhpParser\Node\Expr\Array_) {
            return false;
        }

        if ($expr instanceof \PhpParser\Node\Scalar\String_) {
            return $expr->value;
        }

        // Fall back to inferred type — handles variables holding string literals
        // (`const KEY = 'app.name'; config(self::KEY)`) where Psalm has already
        // resolved the expression. Returns null when the NodeTypeProvider has
        // no entry (typical inside the facade __callStatic dispatch).
        $type = $nodeTypeProvider->getType($expr);

        if (!$type instanceof Union || !$type->isSingleStringLiteral()) {
            return null;
        }

        return $type->getSingleStringLiteral()->value;
    }

    /**
     * Read the effective default-arg type from a `config()` / `Repository::get()`
     * call site. Returns `Type::getNull()` when no default is supplied (Laravel's
     * signature default) and `Type::getMixed()` when the default arg is present
     * but its type cannot be read — preserves the "default may be anything"
     * possibility instead of collapsing to null.
     *
     * Inspects the raw AST first for literal expressions. Psalm's
     * {@see \Psalm\NodeTypeProvider} returns null for argument nodes inside the
     * facade `__callStatic` dispatch, so a literal `'fallback'` would otherwise
     * fall back to `mixed` and lose narrowing precision.
     *
     * @param list<\PhpParser\Node\Arg> $call_args
     */
    private static function readDefaultTypeAt(array $call_args, int $index, \Psalm\NodeTypeProvider $nodeTypeProvider): Union
    {
        if (!isset($call_args[$index])) {
            return Type::getNull();
        }

        $expr = $call_args[$index]->value;

        $literal = self::inferLiteralFromAst($expr);
        if ($literal !== null) {
            return $literal;
        }

        return $nodeTypeProvider->getType($expr) ?? Type::getMixed();
    }

    /**
     * Best-effort inference of a default-arg type from the raw AST. Covers the
     * literal forms that Psalm's NodeTypeProvider cannot resolve inside the
     * Facade `__callStatic` dispatch.
     */
    private static function inferLiteralFromAst(\PhpParser\Node\Expr $expr): ?Union
    {
        if ($expr instanceof \PhpParser\Node\Scalar\String_) {
            return Type::getString();
        }

        if ($expr instanceof \PhpParser\Node\Scalar\Int_) {
            return Type::getInt();
        }

        if ($expr instanceof \PhpParser\Node\Scalar\Float_) {
            return Type::getFloat();
        }

        if ($expr instanceof \PhpParser\Node\Expr\ConstFetch) {
            $name = $expr->name->toLowerString();
            return match ($name) {
                'true', 'false' => Type::getBool(),
                'null' => Type::getNull(),
                default => null,
            };
        }

        if ($expr instanceof \PhpParser\Node\Expr\Array_) {
            return Type::getArray();
        }

        return null;
    }

    /**
     * Walk a default-arg Union and downgrade literal scalar atomics to their
     * general form. Mirrors Larastan's `GeneralizePrecision::lessSpecific()` —
     * Psalm has no direct equivalent. Closures (`fn() => 'bar'`) collapse to
     * their generalized return type; closures without a declared return type
     * contribute `mixed` to the result (so `string | Closure(): void` becomes
     * `string | mixed`, not just `mixed`).
     *
     * Atomics left untouched: TNonEmptyString, named objects, arrays, mixed.
     * Their precision is already useful and the caller almost always benefits
     * from preserving them.
     *
     * @psalm-mutation-free
     */
    public static function generalizeDefault(Union $defaultType): Union
    {
        $atomics = [];
        $closureReturnTypes = [];

        foreach ($defaultType->getAtomicTypes() as $atomic) {
            // Closure default — `config('foo', fn () => 'bar')` resolves the
            // closure via value(). Defer to the recursively generalized return
            // type. TClosure must be checked BEFORE TNamedObject parent class
            // wins a default arm.
            if ($atomic instanceof TClosure) {
                $closureReturnTypes[] = $atomic->return_type;
                continue;
            }

            $atomics[] = match (true) {
                $atomic instanceof TLiteralString => new TString(),
                $atomic instanceof TLiteralInt => new TInt(),
                $atomic instanceof TLiteralFloat => new TFloat(),
                $atomic instanceof TTrue, $atomic instanceof TFalse => new TBool(),
                default => $atomic,
            };
        }

        foreach ($closureReturnTypes as $returnType) {
            if ($returnType === null) {
                // Closure with no declared return → static analysis cannot
                // follow the body. Contribute TMixed without discarding atomics
                // already collected from other union members.
                $atomics[] = new TMixed();
                continue;
            }

            foreach (self::generalizeDefault($returnType)->getAtomicTypes() as $returnAtomic) {
                $atomics[] = $returnAtomic;
            }
        }

        if ($atomics === []) {
            // Defensive fallback — every Union must carry at least one atomic.
            // Reaches here only if the input itself was somehow empty.
            return Type::getMixed();
        }

        return new Union($atomics);
    }

    private function warm(string $key): void
    {
        if (\array_key_exists($key, $this->cache)) {
            return;
        }

        try {
            if (!$this->config->has($key)) {
                $this->cache[$key] = null;
                return;
            }

            $this->cache[$key] = ConfigValueReflector::reflect($this->config->get($key));
        } catch (\Throwable) {
            // Cache mixed so retries are cheap. The stub's mixed return still
            // applies at the call site; failure here is silent by design.
            $this->cache[$key] = Type::getMixed();
        }
    }
}
