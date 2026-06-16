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
 * Resolves dot-notation config keys against the booted Laravel app to a Psalm
 * Union describing the runtime value (generalized — see {@see ConfigValueReflector}).
 *
 * Cache trichotomy via `array_key_exists`:
 *   - missing entry  → not yet computed
 *   - `null` value   → key absent from config (Repository::has() === false)
 *   - `Union` value  → key present (may be `Union<TNull>` if value is literally null)
 *
 * Singleton mirrors {@see \Psalm\LaravelPlugin\Handlers\Auth\AuthConfigAnalyzer}:
 * production goes through {@see instance()}, unit tests construct directly
 * against a fake repository.
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
    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Swallows `ConfigRepositoryProvider::get()` failures with a
     * {@see ThrowingConfigRepository} sentinel. Hook handlers run outside
     * {@see \Psalm\LaravelPlugin\Plugin::__invoke}'s try/catch, so a propagated
     * throw would crash analysis at every `config()` callsite.
     */
    public static function instance(): self
    {
        if (self::$instance instanceof \Psalm\LaravelPlugin\Util\ConfigKeyResolver) {
            return self::$instance;
        }

        try {
            return self::$instance = new self(ConfigRepositoryProvider::get());
        } catch (\Throwable) {
            return self::$instance = new self(new ThrowingConfigRepository());
        }
    }

    /**
     * Test-only. The booted Repository is immutable for the Psalm process
     * lifetime, so production never invalidates.
     *
     * @psalm-api
     * @psalm-external-mutation-free
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Mirrors `Arr::get` runtime semantics:
     *
     *   - key absent → generalized default
     *   - key present → reflected value (default ignored, even if null)
     *
     * The default fires only when {@see Arr::exists()} returns false. A
     * stored null with `array_key_exists` === true is returned verbatim.
     */
    public function resolveCallReturnType(string $key, Union $defaultType): Union
    {
        $this->warm($key);

        $reflected = $this->cache[$key];

        if ($reflected === null) {
            // Generalize so `'fallback'` literals don't trigger spurious `===`
            // warnings at the call site.
            return self::generalizeDefault($defaultType);
        }

        return $reflected;
    }

    /**
     * Shared entry point for both `config()` and `Repository::get()` handlers.
     * Returns null on non-narrowable shapes (no args, dynamic key, array
     * first-arg) so the caller defers to the stub.
     *
     * @param list<\PhpParser\Node\Arg> $call_args
     */
    public static function resolveFromCallArgs(array $call_args, \Psalm\NodeTypeProvider $nodeTypeProvider): ?Union
    {
        $key = self::extractLiteralKey($call_args, $nodeTypeProvider);

        if ($key === null || $key === false) {
            return null;
        }

        return self::instance()->resolveCallReturnType($key, self::readDefaultTypeAt($call_args, 1, $nodeTypeProvider));
    }

    /**
     * AST-first because Psalm's NodeTypeProvider returns null inside the Facade
     * `__callStatic` dispatch (hook fires before arg types resolve). Falls back
     * to the type provider for resolvable non-literal expressions.
     *
     * Return contract:
     *   - string → key resolved
     *   - false  → array first arg (setter / multi-key form, defer to stub)
     *   - null   → dynamic / unresolvable (defer to mixed)
     *
     * @param list<\PhpParser\Node\Arg> $call_args
     */
    private static function extractLiteralKey(
        array $call_args,
        \Psalm\NodeTypeProvider $nodeTypeProvider,
    ): string|false|null {
        if ($call_args === []) {
            return null;
        }

        // Named args (`config(default: 'x', key: 'app.debug')`) — positional
        // extraction would treat the wrong slot as the key. Bail to the stub.
        foreach ($call_args as $arg) {
            if ($arg->name !== null) {
                return null;
            }
        }

        $expr = $call_args[0]->value;

        if ($expr instanceof \PhpParser\Node\Expr\Array_) {
            return false;
        }

        if ($expr instanceof \PhpParser\Node\Scalar\String_) {
            return $expr->value;
        }

        // Handles `config(self::KEY)` where Psalm has resolved the expression.
        $type = $nodeTypeProvider->getType($expr);

        if (!$type instanceof Union || !$type->isSingleStringLiteral()) {
            return null;
        }

        return $type->getSingleStringLiteral()->value;
    }

    /**
     * Sentinels:
     *   - no default arg → `Type::getNull()` (Laravel's signature default)
     *   - present but unresolvable → `Type::getMixed()` (preserve "default may
     *     be anything" rather than collapse to null)
     *
     * AST-first for literal expressions — Psalm's NodeTypeProvider returns
     * null inside the Facade `__callStatic` dispatch.
     *
     * @param list<\PhpParser\Node\Arg> $call_args
     */
    private static function readDefaultTypeAt(
        array $call_args,
        int $index,
        \Psalm\NodeTypeProvider $nodeTypeProvider,
    ): Union {
        if (!isset($call_args[$index])) {
            return Type::getNull();
        }

        $expr = $call_args[$index]->value;

        $literal = self::inferLiteralFromAst($expr);
        if ($literal instanceof \Psalm\Type\Union) {
            return $literal;
        }

        return $nodeTypeProvider->getType($expr) ?? Type::getMixed();
    }

    /** Literal forms Psalm's NodeTypeProvider can't resolve in `__callStatic`. */
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
     * Downgrade literal scalar atomics to their general form. Mirrors Larastan's
     * `GeneralizePrecision::lessSpecific()`. Closures resolve to their
     * generalized return type; untyped closures contribute `mixed` without
     * dropping union siblings (`string | Closure(): void` → `string | mixed`).
     *
     * @psalm-mutation-free
     */
    public static function generalizeDefault(Union $defaultType): Union
    {
        $atomics = [];
        $closureReturnTypes = [];

        foreach ($defaultType->getAtomicTypes() as $atomic) {
            // Must match before TNamedObject's default arm — TClosure extends it.
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
            if (!$returnType instanceof \Psalm\Type\Union) {
                // Untyped closure body — contribute mixed without discarding
                // siblings already collected.
                $atomics[] = new TMixed();
                continue;
            }

            foreach (self::generalizeDefault($returnType)->getAtomicTypes() as $returnAtomic) {
                $atomics[] = $returnAtomic;
            }
        }

        // Every Union needs at least one atomic; empty input is defensive only.
        return $atomics === [] ? Type::getMixed() : new Union($atomics);
    }

    private function warm(string $key): void
    {
        if (\array_key_exists($key, $this->cache)) {
            return;
        }

        // has() / get() can blow up on partial bootstrap or exploding service
        // providers — degrade to mixed so analysis continues. Reflector is pure
        // (input-typed `mixed`, no internal throws), so wrapping it under the
        // same catch costs nothing in practice while keeping the get() result
        // off a local mixed variable (preserves 100% type coverage).
        try {
            if (!$this->config->has($key)) {
                $this->cache[$key] = null;
                return;
            }

            $this->cache[$key] = ConfigValueReflector::reflect($this->config->get($key));
        } catch (\Throwable) {
            $this->cache[$key] = Type::getMixed();
        }
    }
}
