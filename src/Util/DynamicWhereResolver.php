<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use Psalm\Codebase;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Union;

/**
 * Shared helpers for Laravel's dynamic where{Column} magic method resolution.
 *
 * Owns the runtime enable flag, the validation cache, the typed-param hand-off cache,
 * and the column-matching primitives used by both:
 *
 * - {@see \Psalm\LaravelPlugin\Handlers\Magic\MethodForwardingHandler} relation chains
 *   (e.g. `$user->posts()->whereTitle('foo')`).
 * - {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelMethodHandler} direct static and
 *   instance calls on Model subclasses (e.g. `Server::whereUuid($u)`,
 *   `$server->whereUuid($u)`). See https://github.com/psalm/psalm-plugin-laravel/issues/1000.
 *
 * Two complementary validation entry points exist because the relevant Psalm hooks expose
 * different inputs:
 *
 * - {@see resolveColumnType} precise validation that requires the ORIGINAL camel-cased
 *   method name from the AST. Splits the suffix on `(?:And|Or)(?=[A-Z])` boundaries.
 *   Used by `MethodReturnTypeProvider` and `MethodParamsProvider` hooks (both expose the
 *   AST via `getStmt()` / `getCallArgs()`).
 * - {@see methodMatchesColumns} best-effort validation from a lowercase-only method
 *   name. Used by `MethodExistenceProvider`, which doesn't receive an AST node. Performs
 *   a bounded backtracking match against normalised property names with `and`/`or`
 *   connectors. Slightly looser than the precise split (the lowercase form can't
 *   distinguish `wherefooandbar` as `[Foo, Bar]` vs an unsplittable `[FooAndBar]` column),
 *   but that only widens existence to extra true-positives; the return-type and params
 *   providers still call {@see resolveColumnType} for the strict camel-cased check.
 *
 * @psalm-external-mutation-free
 */
final class DynamicWhereResolver
{
    /**
     * Larastan parity: split a where{Column} suffix on And/Or boundaries followed by an
     * uppercase letter. Mirrors `Illuminate\Database\Query\Builder::dynamicWhere`'s
     * `(And|Or)(?=[A-Z])`; we use a non-capturing group since we don't need the connector.
     */
    private const SEGMENT_SPLIT_PATTERN = '/(?:And|Or)(?=[A-Z])/';

    private static bool $enabled = false;

    /**
     * Cache: "ModelClass:originalMethodName" -> three-state validation result.
     *
     * Keyed by model class + ORIGINAL-CASE method name (not lowercase). The original case
     * is needed because Laravel's runtime `Builder::dynamicWhere` splits the suffix on
     * `(?:And|Or)(?=[A-Z])`, which only fires on properly camel-cased boundaries. The same
     * lowercase name can therefore come from a splittable call (`whereFooAndBar`) or a
     * non-splittable one (`wherefooandbar`), and they validate differently.
     *
     * Values:
     *   - `false`: validation failed (one or more segments don't match a declared
     *     pseudo property). Caller falls through to mixed.
     *   - `null`: validation passed but no scalar column type can be handed off to the
     *     typed-parameter checker (multi-segment call, or single-segment whose type is
     *     object/array Carbon, BackedEnum, json casts).
     *   - `Union`: validation passed AND the call is single-segment with a scalar column
     *     type. The typed-param hand-off path (issue #928) consumes this.
     *
     * @var array<string, false|null|Union>
     */
    private static array $columnTypeCache = [];

    /**
     * Hand-off cache: `methodName . ':' . spl_object_id($firstArg)` -> column type.
     *
     * Populated during return-type resolution; consumed immediately after by the params
     * provider (issue #928). Both hooks read args from the same `$stmt->getArgs()` array,
     * so the `Arg` node identity is stable across the producer/consumer pair.
     *
     * The method-name prefix defends against PHP recycling an `spl_object_id` from a
     * freed `Arg` node into an unrelated later call without it, the consumer could
     * pick up a stale column type from a different where{Column} method whose Arg node
     * had the same id.
     *
     * @var array<string, Union>
     */
    private static array $pendingColumnType = [];

    /**
     * Cache: lowercase model FQCN -> normalised property name -> property type.
     *
     * `pseudo_property_get_types` is populated by Psalm's `Populator` during the scan
     * phase and frozen before any method-existence / return-type / params provider
     * fires (Populator runs to completion before AfterCodebasePopulated). Memoising
     * the normalised map per model removes one `str_replace` + `strtolower` over
     * every property for every distinct camel-case where{Column} call. A null entry
     * encodes "storage was missing or had no pseudo-property entries" so the negative
     * lookup short-circuits identically.
     *
     * @var array<lowercase-string, array<string, Union>|null>
     */
    private static array $normalizedPropertiesCache = [];

    /**
     * Enable dynamic where{Column} resolution. Wired from `Plugin::registerHandlers()`
     * when `<resolveDynamicWhereClauses value="true" />` is set (default true).
     *
     * @psalm-external-mutation-free
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    /** @psalm-external-mutation-free */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Reset all state. Called by handler init() pathways so a re-bootstrap doesn't leak
     * caches across analysis runs. The {@see $enabled} flag is preserved it's owned by
     * Plugin config, not by any single handler's init().
     *
     * @psalm-external-mutation-free
     */
    public static function reset(): void
    {
        self::$columnTypeCache = [];
        self::$pendingColumnType = [];
        self::$normalizedPropertiesCache = [];
    }

    /**
     * Check whether a method name looks like a Laravel dynamic where{Column} call.
     *
     * The pattern requires the method to start with "where" and have at least one more
     * character (e.g. "wheretitle"). Methods that exactly equal "where" are excluded
     * they are declared on Builder.
     *
     * @psalm-pure
     */
    public static function isDynamicWhereMethod(string $methodName): bool
    {
        return \strlen($methodName) > 5 && \str_starts_with($methodName, 'where');
    }

    /**
     * Pull the original camel-cased method name from the AST, falling back to the
     * already-lowercased event method name when the call uses a dynamic name
     * (e.g. `$x->{$var}()`). The camel case matters because the And/Or segment split
     * requires uppercase boundaries that Psalm strips from getMethodNameLowercase().
     *
     * Both MethodCall and StaticCall expose `$stmt->name` as `Identifier|Expr` the
     * Identifier branch carries the original spelling; non-Identifier names go through
     * the fallback. Psalm's analyzer short-circuits dynamic-name MethodCalls before
     * invoking return-type providers, so the fallback branch is effectively dead for
     * the MethodCall case; kept for StaticCall and future Psalm versions.
     *
     * @psalm-mutation-free
     */
    public static function originalMethodName(
        MethodCall|StaticCall $stmt,
        string $lowercaseFallback,
    ): string {
        $name = $stmt->name;

        return $name instanceof Identifier ? $name->name : $lowercaseFallback;
    }

    /**
     * Lowercase-only match: does the given lowercase where{Column} method name
     * correspond to one or more columns on the model?
     *
     * Used by the method-existence provider where the AST isn't available. Performs a
     * bounded backtracking match: try to consume the suffix as `prop( (and|or) prop )*`
     * for any combination of declared @property entries on the model.
     *
     * Returns `true` iff at least one partition validates. False positives are bounded
     * by the property set (a `where<X>` call only matches when every lowercase
     * substring lines up with a real property), so this widens existence but cannot
     * confirm a column that isn't declared.
     *
     * Multi-segment behaviour: with no uppercase boundaries to anchor against, the
     * match could in principle find multiple valid partitions for ambiguous property
     * sets (e.g. properties `foo`, `bar`, `foobar` vs method `wherefooorbar` the
     * "or" might be a connector or part of a column name). The function does not
     * disambiguate; any valid partition is sufficient to confirm existence. The
     * return-type and params providers still run the strict camel-cased validation
     * via {@see resolveColumnType}.
     *
     * @param class-string<Model> $modelClass
     * @psalm-external-mutation-free
     */
    public static function methodMatchesColumns(
        Codebase $codebase,
        string $modelClass,
        string $methodNameLower,
    ): bool {
        if (!self::isDynamicWhereMethod($methodNameLower)) {
            return false;
        }

        $normalized = self::getNormalizedProperties($codebase, $modelClass);

        if ($normalized === null || $normalized === []) {
            return false;
        }

        return self::partitionExists(\substr($methodNameLower, 5), \array_keys($normalized));
    }

    /**
     * Validate a dynamic where{Column} method call against the model's declared @property
     * entries and return the column type when (and only when) a single scalar column was
     * matched.
     *
     * Mirrors Larastan's `BuilderHelper::dynamicWhere` (split on And/Or capitalised boundaries,
     * every segment must correspond to a declared column). The suffix after "where" is split
     * by `(?:And|Or)(?=[A-Z])`, so the ORIGINAL camel-cased method name from the AST is
     * required pull it from `$stmt->name` rather than the lowercased event method name.
     *
     * Pseudo-property entries are normalised by stripping `$` and underscores and
     * lowercasing (so `$email_address` collapses to `emailaddress`); each segment goes
     * through the same normalisation and the call is rejected on the first mismatch.
     *
     * Return values:
     *   - `false`: at least one segment doesn't match a declared property. Caller skips
     *     the dynamic where resolution entirely.
     *   - `null`: every segment matched but no scalar column type is suitable for the
     *     typed-parameter hand-off (multi-segment, or single-segment whose type contains
     *     an object/array Carbon, BackedEnum, json casts). Caller returns the chain
     *     type without populating the hand-off cache.
     *   - `Union`: every segment matched, the call is single-segment, and the column
     *     type is scalar. Caller queues this type for the params provider (issue #928).
     *
     * @param class-string<Model> $modelClass
     * @psalm-external-mutation-free
     */
    public static function resolveColumnType(
        Codebase $codebase,
        string $modelClass,
        string $originalMethodName,
    ): false|Union|null {
        $key = $modelClass . ':' . $originalMethodName;

        if (\array_key_exists($key, self::$columnTypeCache)) {
            return self::$columnTypeCache[$key];
        }

        $suffix = \substr($originalMethodName, 5);

        $segments = \preg_split(self::SEGMENT_SPLIT_PATTERN, $suffix);

        // preg_split with this literal pattern cannot fail at runtime; the guard is a
        // Psalm narrowing concession (return type is non-empty-list<string>|false).
        if ($segments === false) {
            return self::$columnTypeCache[$key] = false;
        }

        $normalized = self::getNormalizedProperties($codebase, $modelClass);

        if ($normalized === null) {
            return self::$columnTypeCache[$key] = false;
        }

        $matchedColumnTypes = [];
        foreach ($segments as $segment) {
            $segmentKey = \strtolower($segment);

            if ($segmentKey === '' || !isset($normalized[$segmentKey])) {
                return self::$columnTypeCache[$key] = false;
            }

            $matchedColumnTypes[] = $normalized[$segmentKey];
        }

        if (\count($matchedColumnTypes) !== 1) {
            return self::$columnTypeCache[$key] = null;
        }

        $columnType = $matchedColumnTypes[0];

        // Object/array column types (Carbon, BackedEnum, json casts) skip the typed-param
        // hand-off Laravel coerces strings/ints to these at the query layer, so narrowing
        // to the property type would mass-regress real codebases.
        if ($columnType->hasObjectType() || $columnType->hasArray()) {
            return self::$columnTypeCache[$key] = null;
        }

        return self::$columnTypeCache[$key] = $columnType;
    }

    /**
     * Queue a column type for the params provider to consume on the matching call.
     *
     * @psalm-external-mutation-free
     */
    public static function storePendingColumnType(string $methodName, Arg $firstArg, Union $type): void
    {
        self::$pendingColumnType[$methodName . ':' . \spl_object_id($firstArg)] = $type;
    }

    /**
     * Read and remove the column type queued by the matching return-type provider,
     * returning a typed single-parameter list when the call has exactly one argument.
     * Returns null otherwise so the caller falls back to a permissive variadic-mixed
     * signature.
     *
     * Only the 1-argument value form is type-checked. Laravel's runtime
     * `Builder::dynamicWhere` always uses `=` as the operator and silently drops every
     * argument past the first (see `Query\Builder::addDynamic`), so the 2-arg "operator
     * form" (`whereTitle('=', 'foo')`) is a runtime bug. Rather than blessing it (which
     * would over-accept invalid types in the value position) or rejecting it (which
     * would surface as TooManyArguments on legacy code patterns that issue #928's
     * caveats explicitly ask us to tolerate), 2+ arg calls fall through to the variadic
     * fallback. Larastan does the same via a single-optional-mixed-variadic
     * `DynamicWhereParameterReflection`.
     *
     * @param list<Arg>|null $callArgs
     * @return list<FunctionLikeParameter>|null
     * @psalm-external-mutation-free
     */
    public static function consumeTypedParams(string $methodName, ?array $callArgs): ?array
    {
        if ($callArgs === null || \count($callArgs) !== 1) {
            return null;
        }

        $key = $methodName . ':' . \spl_object_id($callArgs[0]);

        if (!isset(self::$pendingColumnType[$key])) {
            return null;
        }

        $columnType = self::$pendingColumnType[$key];
        unset(self::$pendingColumnType[$key]);

        return [new FunctionLikeParameter('value', by_ref: false, type: $columnType, is_optional: false)];
    }

    /**
     * Variadic mixed signature shared by relation-chain and Model-direct dynamic-where
     * fallbacks when the typed-param hand-off doesn't apply.
     *
     * @return list<FunctionLikeParameter>
     * @psalm-pure
     */
    public static function variadicMixedParams(): array
    {
        return [new FunctionLikeParameter('args', by_ref: false, type: Type::getMixed(), is_variadic: true)];
    }

    /**
     * @param class-string<Model> $modelClass
     * @return array<string, Union>|null Normalised property name -> property type. Null when storage is missing.
     * @psalm-external-mutation-free
     */
    private static function getNormalizedProperties(Codebase $codebase, string $modelClass): ?array
    {
        $cacheKey = \strtolower($modelClass);

        if (\array_key_exists($cacheKey, self::$normalizedPropertiesCache)) {
            return self::$normalizedPropertiesCache[$cacheKey];
        }

        try {
            $storage = $codebase->classlike_storage_provider->get($cacheKey);
        } catch (\InvalidArgumentException) {
            return self::$normalizedPropertiesCache[$cacheKey] = null;
        }

        $normalized = [];
        foreach ($storage->pseudo_property_get_types as $propName => $propType) {
            $normalized[\strtolower(\str_replace(['$', '_'], '', $propName))] = $propType;
        }

        return self::$normalizedPropertiesCache[$cacheKey] = $normalized;
    }

    /**
     * Iterative DP partition check: can the suffix be consumed as `prop( (and|or) prop )*`?
     *
     * `$reachable[$offset]` is a 2-bit mask:
     *   - bit 0 = offset reached in "expecting property" state
     *   - bit 1 = offset reached in "expecting connector" state
     *
     * Equivalent to the recursive backtracker but with O(n * |props|) worst case instead
     * of exponential — guards against adversarial property sets with overlapping prefixes
     * (e.g. `a`, `aa`, `aaa`) feeding a long `where{...}` call.
     *
     * @param list<string> $normalizedProps
     * @psalm-pure
     */
    private static function partitionExists(string $suffix, array $normalizedProps): bool
    {
        $n = \strlen($suffix);

        if ($n === 0) {
            return false;
        }

        $reachable = \array_fill(0, $n + 1, 0);
        $reachable[0] = 1; // start: expecting a property at offset 0

        for ($i = 0; $i <= $n; $i++) {
            $state = $reachable[$i];

            if ($state === 0) {
                continue;
            }

            if (($state & 1) !== 0) {
                foreach ($normalizedProps as $prop) {
                    $len = \strlen($prop);

                    if ($len === 0 || $i + $len > $n) {
                        continue;
                    }

                    if (\substr_compare($suffix, $prop, $i, $len) === 0) {
                        $reachable[$i + $len] |= 2;
                    }
                }
            }

            if (($state & 2) !== 0) {
                if ($i + 3 <= $n && \substr_compare($suffix, 'and', $i, 3) === 0) {
                    $reachable[$i + 3] |= 1;
                }

                if ($i + 2 <= $n && \substr_compare($suffix, 'or', $i, 2) === 0) {
                    $reachable[$i + 2] |= 1;
                }
            }
        }

        return ($reachable[$n] & 2) !== 0;
    }
}
