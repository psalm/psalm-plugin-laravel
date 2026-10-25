<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ColumnInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelMethodHandler;
use Psalm\LaravelPlugin\Handlers\Magic\MacroDefinition;
use Psalm\LaravelPlugin\Handlers\Magic\MacroRegistry;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Shared helpers for Laravel's dynamic where{Column} magic method resolution.
 *
 * Owns the runtime enable flag, the validation cache and the column-matching primitives
 * used by both:
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
 * - {@see acceptsDynamicWhere} precise validation that requires the ORIGINAL camel-cased
 *   method name from the AST. Splits the suffix on `(?:And|Or)(?=[A-Z])` boundaries.
 *   Used by `MethodReturnTypeProvider` hooks (which expose the AST via `getStmt()`).
 * - {@see methodMatchesColumns} best-effort validation from a lowercase-only method
 *   name. Used by `MethodExistenceProvider`, which doesn't receive an AST node. Performs
 *   a bounded backtracking match against normalised property names with `and`/`or`
 *   connectors. Slightly looser than the precise split (the lowercase form can't
 *   distinguish `wherefooandbar` as `[Foo, Bar]` vs an unsplittable `[FooAndBar]` column),
 *   but that only widens existence to extra true-positives; the return-type providers
 *   still call {@see acceptsDynamicWhere} for the strict camel-cased check.
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
     * Cache: "ModelClass:originalMethodName" -> whether every segment resolves to a column.
     *
     * Keyed by model class + ORIGINAL-CASE method name (not lowercase). The original case
     * is needed because Laravel's runtime `Builder::dynamicWhere` splits the suffix on
     * `(?:And|Or)(?=[A-Z])`, which only fires on properly camel-cased boundaries. The same
     * lowercase name can therefore come from a splittable call (`whereFooAndBar`) or a
     * non-splittable one (`wherefooandbar`), and they validate differently.
     *
     * @var array<string, bool>
     */
    private static array $acceptanceCache = [];

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
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Reset all state, including the enable flag. Called by handler init() pathways so a
     * re-bootstrap doesn't leak caches OR a previously-enabled flag across analysis runs.
     *
     * Plugin::registerHandlers() re-runs enable() after init() when the config still has
     * `<resolveDynamicWhereClauses value="true" />`, so a config flip true->false in a
     * second bootstrap correctly leaves the resolver disabled. Without resetting the flag,
     * the second bootstrap would inherit "enabled" from the first run and silently ignore
     * the new config value.
     */
    public static function reset(): void
    {
        self::$enabled = false;
        self::$acceptanceCache = [];
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
    public static function originalMethodName(MethodCall|StaticCall $stmt, string $lowercaseFallback): string
    {
        $name = $stmt->name;

        return $name instanceof Identifier ? $name->name : $lowercaseFallback;
    }

    /**
     * Lowercase-only match: does the given lowercase where{Column} method name
     * correspond to one or more columns on the model?
     *
     * Used by the method-existence provider where the AST isn't available. Performs a
     * bounded backtracking match: try to consume the suffix as `prop( (and|or) prop )*`
     * for any combination of declared @property entries and complete-schema migration
     * columns on the model (see {@see schemaColumns}).
     *
     * Returns `true` iff at least one partition validates. False positives are bounded
     * by the candidate set (a `where<X>` call only matches when every lowercase
     * substring lines up with a real property or column), so this widens existence but
     * cannot confirm a column that isn't declared. Schema columns are compared with
     * underscores stripped, so `line_1` also matches `whereline1`, which Laravel would
     * resolve as the column `line1`; the strict {@see acceptsDynamicWhere} rejects it and
     * the callers then decline instead of claiming the call.
     *
     * Multi-segment behaviour: with no uppercase boundaries to anchor against, the
     * match could in principle find multiple valid partitions for ambiguous property
     * sets (e.g. properties `foo`, `bar`, `foobar` vs method `wherefooorbar` the
     * "or" might be a connector or part of a column name). The function does not
     * disambiguate; any valid partition is sufficient to confirm existence. The
     * return-type provider still runs the strict camel-cased validation
     * via {@see acceptsDynamicWhere}.
     *
     * @param class-string<Model> $modelClass
     */
    public static function methodMatchesColumns(Codebase $codebase, string $modelClass, string $methodNameLower): bool
    {
        if (!self::isDynamicWhereMethod($methodNameLower)) {
            return false;
        }

        $candidates = \array_keys(self::getNormalizedProperties($codebase, $modelClass) ?? []);

        foreach (self::schemaColumns($modelClass) ?? [] as $columnName => $_column) {
            $candidates[] = \strtolower(\str_replace('_', '', $columnName));
        }

        // Underscores are stripped on both sides, so `whereuser_id` (Laravel queries `user_id`) matches too.
        return $candidates !== []
            && self::partitionExists(\str_replace('_', '', \substr($methodNameLower, 5)), $candidates);
    }

    /**
     * {@see acceptsDynamicWhere} for a relation's related-model union (`HasMany<A|B, P>`). Every
     * alternative must be a model that accepts the suffix, otherwise one branch would query a
     * missing column.
     */
    public static function relatedModelsAcceptDynamicWhere(
        Codebase $codebase,
        Union $relatedModels,
        string $originalMethodName,
    ): bool {
        foreach ($relatedModels->getAtomicTypes() as $atomic) {
            if (
                !$atomic instanceof TNamedObject
                || !ClassLineage::isA($codebase, $atomic->value, Model::class)
                || !self::acceptsDynamicWhere($codebase, $atomic->value, $originalMethodName, true)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a macro or `@method` pseudo-method owned by a layer that Laravel consults BEFORE its
     * forwarding to `Query\Builder` claims this name: `Eloquent\Builder::__call` dispatches its macros
     * and the model's custom builder (the plugin models local macros as that builder's pseudo-methods),
     * and `Relation::__call` its own macros first, but only on relation receivers. `Query\Builder` macros
     * never change the Eloquent wrapper's return value, so they do not claim it. Runtime macros come
     * from {@see MacroRegistry} (available before population); pseudo-methods from storage.
     *
     * @param class-string<Model> $modelClass
     */
    private static function isBuilderMethodOwned(Codebase $codebase, string $modelClass, string $methodNameLower, bool $viaRelation): bool
    {
        $owners = [EloquentBuilder::class, ModelMethodHandler::getBuilderClassForModel($modelClass)];

        if ($viaRelation) {
            $owners[] = Relation::class;
        }

        foreach ($owners as $owner) {
            if (self::declaresMacro($codebase, $owner, $methodNameLower)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Runtime macros come from {@see MacroRegistry}; `@method` pseudo-methods from class storage.
     */
    private static function declaresMacro(Codebase $codebase, string $owner, string $methodNameLower): bool
    {
        if (MacroRegistry::get($owner, $methodNameLower) instanceof MacroDefinition) {
            return true;
        }

        try {
            $storage = $codebase->classlike_storage_provider->get(\strtolower($owner));
        } catch (\InvalidArgumentException) {
            return false;
        }

        return isset($storage->pseudo_methods[$methodNameLower]) || isset($storage->declaring_pseudo_method_ids[$methodNameLower]);
    }

    /**
     * Whether a dynamic where{Column} call resolves to the columns of the model: every
     * segment of the suffix must be a declared `@property` or a column of the model's
     * complete migration schema, and no builder layer may own the method name.
     *
     * Mirrors Larastan's `BuilderHelper::dynamicWhere` (split on And/Or capitalised boundaries,
     * every segment must correspond to a declared column). The suffix after "where" is split
     * by `(?:And|Or)(?=[A-Z])`, so the ORIGINAL camel-cased method name from the AST is
     * required: pull it from `$stmt->name` rather than the lowercased event method name.
     *
     * Pseudo-property entries are normalised by stripping `$` and underscores and
     * lowercasing (so `$email_address` collapses to `emailaddress`); each segment goes
     * through the same normalisation and the call is rejected on the first mismatch.
     * A segment without a `@property` match falls back to the model's complete migration
     * schema, compared as `Str::snake($segment)` against the exact column name, exactly as
     * Laravel builds the query. Unwarmed or partial metadata leaves the `@property`-only
     * behaviour.
     *
     * @param class-string<Model> $modelClass
     */
    public static function acceptsDynamicWhere(
        Codebase $codebase,
        string $modelClass,
        string $originalMethodName,
        bool $viaRelation = false,
    ): bool {
        // Not cached: a cheap lookup, and the layers consulted depend on the receiver.
        if (self::isBuilderMethodOwned($codebase, $modelClass, \strtolower($originalMethodName), $viaRelation)) {
            return false;
        }

        $key = $modelClass . ':' . $originalMethodName;

        if (\array_key_exists($key, self::$acceptanceCache)) {
            return self::$acceptanceCache[$key];
        }

        $suffix = \substr($originalMethodName, 5);

        $segments = \preg_split(self::SEGMENT_SPLIT_PATTERN, $suffix);

        // preg_split with this literal pattern cannot fail at runtime; the guard is a
        // Psalm narrowing concession (return type is non-empty-list<string>|false).
        if ($segments === false) {
            return self::$acceptanceCache[$key] = false;
        }

        $normalized = self::getNormalizedProperties($codebase, $modelClass);

        if ($normalized === null) {
            return self::$acceptanceCache[$key] = false;
        }

        $schemaColumns = self::schemaColumns($modelClass);

        foreach ($segments as $segment) {
            $segmentKey = \strtolower($segment);

            if ($segmentKey === '') {
                return self::$acceptanceCache[$key] = false;
            }

            // `@property` wins over the schema, as it does for property reads.
            if (isset($normalized[$segmentKey])) {
                continue;
            }

            // Laravel's `Query\Builder::addDynamic()` queries `Str::snake($segment)`, so the
            // schema is matched against that exact column name (`line_1` is unreachable).
            $columnName = Str::snake($segment);

            if ($columnName === '' || !isset($schemaColumns[$columnName])) {
                return self::$acceptanceCache[$key] = false;
            }
        }

        return self::$acceptanceCache[$key] = true;
    }

    /**
     * Argument signature of every dynamic where{Column}(): Laravel forwards the value to
     * `Query\Builder::where()`, which takes far more than a column's own type (null, arrays,
     * Expression, BackedEnum, DateTimeInterface, subquery Closures), and Psalm asks for the
     * params before the arguments are analyzed, so nothing finer than `mixed` is safe. Variadic
     * because a single-segment call ignores the arguments past the first and multi-segment
     * calls (`whereFirstNameAndLastName($a, $b)`) take one per segment. A single `$value`
     * parameter was tried and rejected: builder macros run with their own parameter names.
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
     * Migration columns usable as dynamic-where candidates, or null unless the model's schema
     * section is authoritative: an unwarmed or partial entry keeps the `@property`-only
     * behaviour, since an empty schema there proves nothing.
     *
     * @param class-string<Model> $modelClass
     * @return array<non-empty-string, ColumnInfo>|null
     */
    private static function schemaColumns(string $modelClass): ?array
    {
        $metadata = ModelMetadataRegistry::for($modelClass);

        if (!$metadata instanceof ModelMetadata || !$metadata->isComplete(ModelMetadata::SECTION_SCHEMA)) {
            return null;
        }

        return $metadata->schema()->all();
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

                    if ($len === 0 || ($i + $len) > $n) {
                        continue;
                    }

                    if (\substr_compare($suffix, $prop, $i, $len) === 0) {
                        $reachable[$i + $len] |= 2;
                    }
                }
            }

            if (($state & 2) !== 0) {
                if (($i + 3) <= $n && \substr_compare($suffix, 'and', $i, 3) === 0) {
                    $reachable[$i + 3] |= 1;
                }

                if (($i + 2) <= $n && \substr_compare($suffix, 'or', $i, 2) === 0) {
                    $reachable[$i + 2] |= 1;
                }
            }
        }

        return ($reachable[$n] & 2) !== 0;
    }
}
