<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\GuardsAttributes;
use Illuminate\Database\Eloquent\Concerns\HasGlobalScopes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\EloquentModelMethods;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\ModelPropertyResolver;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\ModelQueryPath;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\LaravelPlugin\Issues\UnknownModelAttribute;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Always-on rule (#699): flags a literal array key passed to an Eloquent mass-assignment method that
 * matches no known attribute of the model — the typo `User::create(['nmae' => ...])`. Recognized =
 * {@see ModelMetadata::knownProperties()} ∪ `$fillable` ∪ `@property` pseudo-properties, matched
 * case/separator-insensitively (a JSON-path key by its base column). Self-silences when the model has
 * no column schema, on non-literal arrays/keys, and on ambiguous (non-single-model) receivers, so an
 * always-on rule never floods. Per-expression hook like {@see ModelMakeHandler}; cheap AST rejects first.
 *
 * #1679 extends the check to creation calls on Builder and relation receivers (`Post::query()->create()`,
 * `$user->posts()->createMany()`), resolving the model the call fills from the receiver's projected
 * `TModel` / `TRelatedModel`. Those receivers use their own per-class method allowlists
 * ({@see self::CREATION_RECEIVERS}) rather than {@see self::MASS_ASSIGNMENT_METHODS}: only calls that go
 * through `fill()` qualify, and `Builder::update()` / `HasMany::update()` are query-builder writes that
 * bypass it (the keys are raw column names), so they stay out.
 */
final class UnknownModelAttributeHandler implements AfterExpressionAnalysisInterface
{
    /**
     * Mass-assignment methods taking the attribute map as the first arg (`$attributes`). The
     * `Quietly` twins (events off) and `updateOrFail` (throws) share the shape; `make()` is
     * {@see ModelMakeHandler}'s; the two-arg family (`updateOrCreate`/`firstOrCreate`/`firstOrNew`)
     * takes a lookup map, not a pure write map, and is deferred.
     *
     * @var array<lowercase-string, true>
     */
    private const MASS_ASSIGNMENT_METHODS = [
        'create' => true,
        'forcecreate' => true,
        'createquietly' => true,
        'forcecreatequietly' => true,
        'fill' => true,
        'forcefill' => true,
        'update' => true,
        'updatequietly' => true,
        'updateorfail' => true,
    ];

    /**
     * Builder/relation creation methods taking one attribute map (`$attributes`) that fill the model.
     * `make*` build an unsaved instance; the value `false` marks a map method, `true` a list method.
     *
     * @var array<lowercase-string, false>
     */
    private const MAP_CREATION_METHODS = [
        'make' => false,
        'create' => false,
        'createquietly' => false,
        'forcecreate' => false,
        'forcecreatequietly' => false,
    ];

    /**
     * Relation methods taking a list of attribute maps (`$records`).
     *
     * @var array<lowercase-string, true>
     */
    private const LIST_CREATION_METHODS = [
        'makemany' => true,
        'createmany' => true,
        'createmanyquietly' => true,
        'forcecreatemany' => true,
        'forcecreatemanyquietly' => true,
    ];

    /**
     * Receiver class => [template carrying the filled model, methods that receiver fills it through,
     * delegated hooks those methods run on the way to the model]. A userland override of a hook can
     * rename keys like an override of a listed method, so hooks join the override scan only.
     * BelongsToMany declares only `create*`; its `make`/`forceCreate` reach the Builder through
     * `__call`, so they are not listed. Its second argument is pivot data and is never inspected.
     *
     * @var array<class-string, array{string, array<lowercase-string, bool>, list<lowercase-string>}>
     */
    private const CREATION_RECEIVERS = [
        Builder::class => ['TModel', self::MAP_CREATION_METHODS, ['newmodelinstance']],
        HasOneOrMany::class => [
            'TRelatedModel',
            [...self::MAP_CREATION_METHODS, ...self::LIST_CREATION_METHODS],
            ['setforeignattributesforcreate'],
        ],
        BelongsToMany::class => [
            'TRelatedModel',
            ['create' => false, 'createquietly' => false, 'createmany' => true, 'createmanyquietly' => true],
            [],
        ],
    ];

    /**
     * The only framework classes that declare a scanned builder/relation method (verified against
     * laravel/framework). The check is by exact declaring class, not an `Illuminate\` namespace prefix: a
     * userland class can live in that namespace. A future framework redeclaration outside this list only
     * costs a false negative.
     *
     * @var list<class-string>
     */
    private const TRUSTED_RECEIVER_DECLARERS = [
        Builder::class,
        HasOneOrMany::class,
        MorphOneOrMany::class,
        MorphMany::class,
        BelongsToMany::class,
    ];

    /**
     * Model methods that decide which keys mass assignment accepts and how the model is built. A model
     * lineage (including a trait) redeclaring one outside {@see self::TRUSTED_MODEL_DECLARERS} can admit
     * a virtual key that is no column (a custom-fields trait widening `isFillable()`), so the model's
     * attribute set no longer bounds what is assignable. `setAttribute()`-only overrides are not scanned.
     *
     * @var list<lowercase-string>
     */
    private const MODEL_FILL_METHODS = ['fill', 'forcefill', 'newinstance', 'isfillable', 'fillablefromarray'];

    /** @var list<class-string> */
    private const TRUSTED_MODEL_DECLARERS = [Model::class, GuardsAttributes::class];

    /**
     * Per run: no class implementing `Scope` other than Laravel's `SoftDeletingScope` declares or inherits
     * `extend()`. Null until a chain with a bare `withoutGlobalScopes()` first needs it.
     */
    private static ?bool $scopesNeverExtend = null;

    /**
     * Eloquent read methods taking a `$columns` select list (#2346) => that parameter's position.
     * Same name and position on the 12.27 and 13.20 floors. `all` exists on Model only.
     *
     * @var array<lowercase-string, int<0, 1>>
     */
    private const COLUMN_METHODS = [
        'find' => 1,
        'findorfail' => 1,
        'findornew' => 1,
        'findmany' => 1,
        'findsole' => 1,
        'findor' => 1,
        'first' => 0,
        'firstorfail' => 0,
        'firstor' => 0,
        'sole' => 0,
        'get' => 0,
        'all' => 0,
        'paginate' => 1,
        'simplepaginate' => 1,
        'cursorpaginate' => 1,
    ];

    /**
     * Methods a read method calls on itself that Eloquent's Builder does not declare, so they reach
     * `Builder::__call()`, where a local scope of that name runs first, and a custom builder may declare
     * them. Same on the 12.27 and 13.20 floors: `first()` calls `limit(1)` (`Concerns\BuildsQueries`), the
     * `find*` / `first*` family reaches `first()` or `sole()` (`limit(2)`) through the declared `whereKey()`
     * / `find()` / `first()`, `paginate()` calls `forPage()`, `simplePaginate()` `offset()` and `limit()`,
     * and `cursorPaginate()` `limit()`, `orderBy()` (in `enforceOrderBy()`) and the union bindings setters.
     *
     * @var array<lowercase-string, non-empty-list<lowercase-string>>
     */
    private const READ_FORWARDED_CALLS = [
        'find' => ['limit'],
        'findorfail' => ['limit'],
        'findornew' => ['limit'],
        'findsole' => ['limit'],
        'findor' => ['limit'],
        'first' => ['limit'],
        'firstorfail' => ['limit'],
        'firstor' => ['limit'],
        'sole' => ['limit'],
        'paginate' => ['forpage'],
        'simplepaginate' => ['offset', 'limit'],
        'cursorpaginate' => ['limit', 'orderby', 'setbindings', 'addbinding'],
    ];

    /**
     * Model hooks beside {@see ModelQueryPath::isLaravels()} that decide what a column-list query looks like:
     * the builder factory and base query builder (an override may return a joined or selected query), and
     * the global-scope providers of `HasGlobalScopes` (an override may supply scopes nothing registers).
     *
     * @var list<lowercase-string>
     */
    private const COLUMN_QUERY_HOOKS = [
        'neweloquentbuilder',
        'newbasequerybuilder',
        'getglobalscopes',
        'boothasglobalscopes',
        'resolveglobalscopeattributes',
    ];

    /**
     * Chain links between a model's static query root and the read call that leave the query's select
     * list, FROM and JOIN set untouched: they only add wheres, orders, limits, a row lock, eager loads,
     * or drop global scopes. A positive list on purpose: anything else (`join()`, `from()`, `select()`,
     * `withCount()`, `when()`, a local scope, a macro) may make a `$columns` literal name something other
     * than a column of the model's table, or make the argument dead, so the chain is not checked.
     *
     * @var array<lowercase-string, true>
     */
    private const SHAPE_PRESERVING_METHODS = [
        'where' => true, 'orwhere' => true, 'wherenot' => true, 'orwherenot' => true,
        'wherekey' => true, 'wherekeynot' => true,
        'wherein' => true, 'wherenotin' => true, 'orwherein' => true, 'orwherenotin' => true,
        'wherenull' => true, 'wherenotnull' => true, 'orwherenull' => true, 'orwherenotnull' => true,
        'wherebetween' => true, 'wherenotbetween' => true,
        'wheredate' => true, 'wheremonth' => true, 'whereday' => true, 'whereyear' => true, 'wheretime' => true,
        'wherecolumn' => true, 'wherelike' => true, 'wherenotlike' => true,
        'has' => true, 'doesnthave' => true, 'wherehas' => true, 'orwherehas' => true,
        'wheredoesnthave' => true, 'orwheredoesnthave' => true, 'whererelation' => true, 'orwhererelation' => true,
        'wherebelongsto' => true, 'orwherebelongsto' => true, 'wheremorphedto' => true,
        'orderby' => true, 'orderbydesc' => true, 'latest' => true, 'oldest' => true,
        'inrandomorder' => true, 'reorder' => true,
        'limit' => true, 'take' => true, 'skip' => true, 'offset' => true, 'forpage' => true,
        'lockforupdate' => true, 'sharedlock' => true,
        'with' => true, 'without' => true, 'withonly' => true,
        'withtrashed' => true, 'onlytrashed' => true, 'withouttrashed' => true,
        'withoutglobalscope' => true, 'withoutglobalscopes' => true,
    ];

    /** @inheritDoc */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        // Only static (`Model::create([...])`) and instance (`$model->fill([...])`) calls are in scope.
        if (!$expr instanceof StaticCall && !$expr instanceof MethodCall) {
            return null;
        }

        // Named methods only — `Model::$method()` / `$model->$method()` are not statically known.
        // PHP method names are case-insensitive; the allowlist keys are lowercase.
        if (!$expr->name instanceof Identifier) {
            return null;
        }

        $lowerMethod = \strtolower($expr->name->name);

        if (isset(self::COLUMN_METHODS[$lowerMethod])) {
            self::checkColumnList($expr, $expr->name->name, $event);

            return null;
        }

        $isListMethod = isset(self::LIST_CREATION_METHODS[$lowerMethod]);

        if (
            !isset(self::MASS_ASSIGNMENT_METHODS[$lowerMethod])
            && !isset(self::MAP_CREATION_METHODS[$lowerMethod])
            && !$isListMethod
        ) {
            return null;
        }

        // The attribute map (or, for the `*Many` list methods, the records list) is the first positional
        // argument, or the argument named `attributes` / `records` wherever it sits (`create(joining: [...],
        // attributes: [...])`). Bail on a first-class callable, argument unpacking, or a non-literal array
        // — none expose enumerable literal keys.
        $arg = self::dataArgument($expr->args, $isListMethod ? 'records' : 'attributes');

        $array = $arg?->value;

        if (!$array instanceof Array_) {
            return null;
        }

        // A migration is point-in-time code: it can mass-assign a column a LATER migration drops, which
        // the final replayed schema no longer knows about, so the rule would false-positive on code that
        // was correct when it ran (#1251). Skip a file we recognise as a migration, before the costlier
        // receiver/registry work.
        if (self::isMigrationFile($event->getStatementsSource()->getFilePath())) {
            return null;
        }

        $codebase = $event->getCodebase();

        if ($expr instanceof StaticCall) {
            // Static calls only ever target a Model; `Model::make()` is ModelMakeHandler's.
            $modelClass = isset(self::MASS_ASSIGNMENT_METHODS[$lowerMethod])
                ? self::staticReceiverModel($expr, $event->getStatementsSource(), $codebase)
                : null;
            $resolved = $modelClass === null ? null : [$modelClass, $modelClass];
        } else {
            $resolved = self::instanceReceiver($expr, $lowerMethod, $event, $codebase);
        }

        if ($resolved === null) {
            return null;
        }

        [$modelClass, $receiverClass] = $resolved;

        if (self::declaresUserlandMethod($modelClass, null, self::MODEL_FILL_METHODS, self::TRUSTED_MODEL_DECLARERS, $codebase)) {
            return null;
        }

        $metadata = self::authoritativeMetadata($modelClass);

        if (!$metadata instanceof ModelMetadata) {
            return null;
        }

        $allowedKeys = self::allowedKeys($metadata, $modelClass, $codebase);
        $receiverName = self::shortClassName($receiverClass);
        $modelName = self::shortClassName($modelClass);

        // A list method checks each literal map in the list; non-literal items carry no keys. Only a pure
        // keyless list is checked: a spread or an explicit outer key can merge or overwrite records, so
        // which maps are really used is not statically known.
        /** @var list<Array_> $maps */
        $maps = [];

        if ($isListMethod) {
            foreach ($array->items as $item) {
                if ($item->unpack || $item->key !== null) {
                    return null;
                }

                if ($item->value instanceof Array_) {
                    $maps[] = $item->value;
                }
            }
        } else {
            $maps[] = $array;
        }

        foreach ($maps as $map) {
            foreach ($map->items as $item) {
                // Positional (list-style) and dynamic keys carry no property name and are dropped. A
                // duplicated unknown key is reported at each of its occurrences, at the key node.
                $key = $item->key;

                if (!$key instanceof String_ || self::unknownKeys($allowedKeys, [$key->value]) === []) {
                    continue;
                }

                IssueBuffer::accepts(
                    new UnknownModelAttribute(
                        "{$receiverName}::{$expr->name->name}() is assigned an unknown attribute '{$key->value}'. "
                        . "It matches no column, cast, accessor, relation, \$appends entry, or @property "
                        . "of {$modelName} — check for a typo.",
                        new CodeLocation($event->getStatementsSource(), $key),
                    ),
                    $event->getStatementsSource()->getSuppressedIssues(),
                );
            }
        }

        return null;
    }

    /**
     * Best-effort guess: is this file a Laravel migration? Recognised by either universal signal,
     * without resolving the app's exact migration paths: the migrator's `YYYY_MM_DD_HHMMSS_` filename
     * prefix (every `php artisan make:migration`), or a `migrations` directory in the path. Either is a
     * near-certain migration; a stray match only over-suppresses this always-on rule — the safe
     * direction for a false-positive guard. See {@see UnknownModelAttribute} docs.
     *
     * @psalm-pure
     */
    public static function isMigrationFile(string $filePath): bool
    {
        return \preg_match('#^\d{4}_\d{2}_\d{2}_\d{6}_#', \basename($filePath)) === 1
            || \str_contains(\str_replace('\\', '/', $filePath), '/migrations/');
    }

    /**
     * The raw (original-case) keys naming no allowed attribute, in input order. Pure verdict; the
     * gate, receiver resolution, and allowed-set build happen in the caller. Each key matches through
     * {@see EloquentModelMethods::accessorPropertyKey()} (`fullName` clears `full_name`); a JSON-path
     * key matches by its base column. A key normalizing to null is treated as known, never flagged.
     *
     * @param array<non-empty-lowercase-string, true> $allowedKeys
     * @param list<string>                            $rawKeys
     *
     * @return list<string>
     *
     * @psalm-pure
     */
    public static function unknownKeys(array $allowedKeys, array $rawKeys): array
    {
        $unknown = [];

        foreach ($rawKeys as $rawKey) {
            // Validate the base column of a JSON path, not the whole `column->a->b` string. strstr()
            // returns the segment before the first `->`, or false when the key has none.
            $base = \strstr($rawKey, '->', true);
            $normalized = EloquentModelMethods::accessorPropertyKey($base === false ? $rawKey : $base);

            if ($normalized !== null && !isset($allowedKeys[$normalized])) {
                $unknown[] = $rawKey;
            }
        }

        return $unknown;
    }

    /**
     * The recognized attribute keys for the model, normalized for case- and separator-insensitive
     * matching. Unions three sources so an always-on rule does not flag legitimately-assignable keys:
     * the registry's {@see ModelMetadata::knownProperties()}, the model's `$fillable`, and the model's
     * Psalm pseudo-properties (`@property*` docblocks plus the plugin's synthesized write types).
     *
     * @param ModelMetadata<Model> $metadata
     * @param class-string<Model>  $modelClass
     *
     * @return array<non-empty-lowercase-string, true>
     *
     * @psalm-mutation-free
     */
    private static function allowedKeys(ModelMetadata $metadata, string $modelClass, Codebase $codebase): array
    {
        // knownProperties() keys are already normalized through accessorPropertyKey(), so they are
        // admitted as-is; the $fillable and pseudo-property names below still need normalizing.
        $known = \array_fill_keys(\array_keys($metadata->knownProperties()), true);

        // `$fillable` is an explicit "this name is mass-assignable" declaration, so a fillable entry
        // must never be flagged — even one the schema/cast/accessor parse did not surface (a JSON
        // column, a dynamic attribute). knownProperties() omits `$fillable` by design (there it is a
        // guard-list over columns); this consumer re-admits it. Pseudo-property names then cover user
        // `@property*` docblocks the registry does not fold.
        $rawNames = [...$metadata->fillable, ...self::pseudoPropertyNames($modelClass, $codebase)];

        foreach ($rawNames as $rawName) {
            $normalized = EloquentModelMethods::accessorPropertyKey($rawName);

            if ($normalized !== null) {
                $known[$normalized] = true;
            }
        }

        return $known;
    }

    /**
     * Property names from the model's pseudo-property storage (get and set), with the leading `$`
     * stripped. Covers user `@property` / `@property-read` / `@property-write` docblocks that the
     * registry does not fold, plus the plugin's own synthesized column/accessor/relation write
     * types. Returns raw names; the caller normalizes them.
     *
     * @param class-string<Model> $modelClass
     *
     * @return list<string>
     *
     * @psalm-mutation-free
     */
    private static function pseudoPropertyNames(string $modelClass, Codebase $codebase): array
    {
        try {
            $storage = $codebase->classlike_storage_provider->get(\strtolower($modelClass));
        } catch (\InvalidArgumentException) {
            return [];
        }

        $names = [];

        foreach (\array_keys($storage->pseudo_property_get_types) as $key) {
            $names[] = \ltrim($key, '$');
        }

        foreach (\array_keys($storage->pseudo_property_set_types) as $key) {
            $names[] = \ltrim($key, '$');
        }

        return $names;
    }

    /**
     * Resolve the model FQCN named on the left of a static call, or null when the receiver is not a
     * (resolvable) Eloquent model. Mirrors {@see UndefinedModelRelationHandler::resolveClassName()}: `self`
     * and `static` are special class names Psalm's name resolver does not rewrite to an FQCN, so they
     * must be resolved against the enclosing class explicitly, or the idiomatic `static::create([...])`
     * inside a model method silently bypasses this rule.
     *
     * @return class-string<Model>|null
     */
    private static function staticReceiverModel(StaticCall $expr, StatementsSource $source, Codebase $codebase): ?string
    {
        if (!$expr->class instanceof Name) {
            return null;
        }

        $className = self::resolveClassName($expr->class, $source);

        if ($className === null || !self::isModelSubclass($className, $codebase)) {
            return null;
        }

        return $className;
    }

    /**
     * Resolve a `Name` class reference to an FQCN. `self` / `static` resolve to the enclosing class;
     * `parent` is not resolved. Mirrors {@see UndefinedModelRelationHandler::resolveClassName()}.
     */
    private static function resolveClassName(Name $class, StatementsSource $source): ?string
    {
        if ($class->isSpecialClassName()) {
            return match ($class->toLowerString()) {
                'self', 'static' => $source->getFQCLN(),
                default => null,
            };
        }

        // Psalm's name resolver stores the FQCN as a string on this attribute.
        /** @psalm-var ?string $resolved */
        $resolved = $class->getAttribute('resolvedName');

        return \is_string($resolved) ? $resolved : $class->toString();
    }

    /**
     * The argument bound to `$paramName`: the named argument wherever it sits, else the positional one
     * at `$position`. Null when there is none, an unpack precedes it, or the call is a first-class
     * callable.
     *
     * @param array<array-key, \PhpParser\Node> $args
     *
     * @psalm-capabilities read-props
     */
    private static function dataArgument(array $args, string $paramName, int $position = 0): ?Arg
    {
        foreach (\array_values($args) as $index => $arg) {
            if (!$arg instanceof Arg || $arg->unpack) {
                return null;
            }

            if ($arg->name instanceof Identifier ? $arg->name->name === $paramName : $index === $position) {
                return $arg;
            }
        }

        return null;
    }

    /**
     * The registry metadata, or null unless every section the recognized set is built from is
     * authoritative.
     *
     * @param class-string<Model> $modelClass
     *
     * @return ModelMetadata<Model>|null
     */
    private static function authoritativeMetadata(string $modelClass): ?ModelMetadata
    {
        $metadata = ModelMetadataRegistry::for($modelClass);

        if (
            !$metadata instanceof ModelMetadata
            || !$metadata->isComplete(
                ModelMetadata::SECTION_METHODS
                | ModelMetadata::SECTION_RELATIONS
                | ModelMetadata::SECTION_RUNTIME_CONFIGURATION
                | ModelMetadata::SECTION_SCHEMA
                | ModelMetadata::SECTION_CASTS,
            )
            // A registered table can still have no statically parsed columns (for example when a
            // migration uses dynamic names or Blueprint macros). In that case an unknown-key verdict
            // would treat every real column as a typo, so preserve the rule's conservative bail-out.
            || $metadata->schema()->all() === []
        ) {
            return null;
        }

        return $metadata;
    }

    /**
     * #2346: flag a literal `$columns` entry of a read method that names no known attribute of the model,
     * but only where the literal provably reaches SQL as a column of the model's own table: the call is
     * (or chains from) a static call on the model class through {@see self::SHAPE_PRESERVING_METHODS}
     * links only. A builder held in a variable, a relation, or any other link may carry a join, another
     * FROM, or an earlier select (which makes `$columns` dead: `Query\Builder::get()` keeps existing
     * columns), and the receiver type carries none of that, so those calls are never inspected.
     */
    private static function checkColumnList(MethodCall|StaticCall $expr, string $method, AfterExpressionAnalysisEvent $event): void
    {
        $lowerMethod = \strtolower($method);
        $columns = self::columnLiterals(
            self::dataArgument($expr->args, 'columns', self::COLUMN_METHODS[$lowerMethod] ?? 0)?->value,
        );
        $chain = $columns === [] ? null : self::freshQueryChain($expr);
        $source = $event->getStatementsSource();

        // `all()` is Model's own static; no builder has it.
        if ($chain === null || ($lowerMethod === 'all' && $chain[0] !== $expr) || self::isMigrationFile($source->getFilePath())) {
            return;
        }

        [$root, $chainNames] = $chain;
        $codebase = $event->getCodebase();
        $modelClass = self::staticReceiverModel($root, $source, $codebase);
        $metadata = $modelClass === null ? null : self::authoritativeMetadata($modelClass);

        if ($modelClass === null || !$metadata instanceof ModelMetadata) {
            return;
        }

        $names = [...\array_keys($chainNames), $lowerMethod];
        $called = [...$names, ...self::READ_FORWARDED_CALLS[$lowerMethod] ?? []];
        $builderClass = $metadata->customBuilder;

        if (
            // `$withCount` (a name list or a constraint map) seeds a select on every query, so `$columns` is dead.
            $metadata->hasDefaultCounts
            // `Builder::__call()` runs a local scope before forwarding a query-builder method of that name.
            || \array_intersect_key($metadata->scopes(), \array_flip($called)) !== []
            // The query is not built by Laravel's own Model code on a known builder (`newQuery()` override, ...).
            || !ModelQueryPath::isLaravels($codebase, $modelClass)
            // The model itself declares the root or a link (`Setting::get($key)`), or a query hook.
            || self::declaresUserlandMethod($modelClass, null, [...$names, ...self::COLUMN_QUERY_HOOKS], [...self::TRUSTED_MODEL_DECLARERS, HasGlobalScopes::class], $codebase)
            || ($builderClass !== null && self::redeclaresBuilderMethod($builderClass, $called, $codebase))
        ) {
            return;
        }

        $allowedKeys = self::allowedKeys($metadata, $modelClass, $codebase);
        $unknown = \array_filter(
            $columns,
            static fn(String_ $column): bool => self::unknownKeys($allowedKeys, [$column->value]) !== [],
        );

        // The lineage scan reads files, so it runs last and only when there is something to report. A bare
        // `withoutGlobalScopes()` drops every registered scope, but not what a scope's `extend()` did to the
        // builder at registration, so it lifts the veto only when no scope of the run has a userland `extend()`.
        // The language server reloads files without a plugin reset, so the cached run-wide fact could be stale there.
        $unscoped = ($chainNames['withoutglobalscopes'] ?? false) && !$codebase->server_mode && self::scopesNeverExtend();

        if ($unknown === [] || (!$unscoped && self::mayRegisterGlobalScope($modelClass, $codebase))) {
            return;
        }

        $receiverName = self::shortClassName($root === $expr ? $modelClass : $builderClass ?? Builder::class);
        $modelName = self::shortClassName($modelClass);

        foreach ($unknown as $column) {
            IssueBuffer::accepts(
                new UnknownModelAttribute(
                    "{$receiverName}::{$method}() selects an unknown column '{$column->value}'. "
                    . "It matches no column, cast, accessor, relation, \$appends entry, or @property "
                    . "of {$modelName} — check for a typo.",
                    new CodeLocation($source, $column),
                ),
                $source->getSuppressedIssues(),
            );
        }
    }

    /**
     * Pure syntax verdict: the static call a read call is, or chains from, plus the lowercase names of
     * every link on the way (root included) mapped to "called at least once without arguments". Null
     * unless the root is a class-named static call (`Post::query()`, `Post::where()`, `Post::find()`) and
     * every link is a named {@see self::SHAPE_PRESERVING_METHODS} method call: a variable, property,
     * `new`, function result, nullsafe or dynamic call has unknown provenance.
     *
     * @return array{StaticCall, array<lowercase-string, bool>}|null
     *
     * @psalm-capabilities read-props
     */
    public static function freshQueryChain(MethodCall|StaticCall $call): ?array
    {
        $names = [];
        $node = $call;

        while ($node instanceof MethodCall) {
            $node = $node->var;

            if ((!$node instanceof MethodCall && !$node instanceof StaticCall) || !$node->name instanceof Identifier) {
                return null;
            }

            $name = \strtolower($node->name->name);

            if (!isset(self::SHAPE_PRESERVING_METHODS[$name]) && (!$node instanceof StaticCall || $name !== 'query')) {
                return null;
            }

            $names[$name] = ($names[$name] ?? false) || $node->args === [];
        }

        return $node->class instanceof Name && $node->name instanceof Identifier ? [$node, $names] : null;
    }

    /**
     * The literal entries of a `$columns` argument (`'a'` or `['a', 'b']`) that name a plain column:
     * an identifier, optionally followed by a JSON path (validated by its base column). Everything
     * else is skipped entry by entry, since select-list entries are independent: `*`, a qualified
     * `table.column` / `table.*`, `column as alias`, anything with spaces or parentheses, an unpacked
     * item, and every non-literal (an `Expression`, a variable, a closure passed as `$columns`). A list
     * with any explicit key yields nothing: the key may overwrite another entry before it reaches SQL.
     *
     * @return list<String_>
     *
     * @psalm-capabilities read-props
     */
    public static function columnLiterals(?Expr $columns): array
    {
        $literals = [];
        $items = match (true) {
            $columns instanceof String_ => [$columns],
            $columns instanceof Array_ => $columns->items,
            default => [],
        };

        foreach ($items as $item) {
            if (!$item instanceof String_) {
                if ($item->key !== null) {
                    return [];
                }

                $item = $item->unpack ? null : $item->value;
            }

            if ($item instanceof String_ && \preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:->\S+)?$/D', $item->value) === 1) {
                $literals[] = $item;
            }
        }

        return $literals;
    }

    /**
     * Does a custom builder lineage redeclare anything `Illuminate\Database\Eloquent\Builder` declares
     * (a read method, a link, `__construct`, `__call`, `applyScopes()`, ...) or declare a method named
     * like a link `__call` would otherwise forward? The trusted declarers and the scanned names are read
     * off the stock builder's own storage, so they follow the installed Laravel.
     *
     * @param class-string<Builder>  $builderClass
     * @param list<lowercase-string> $chainNames
     * @psalm-capabilities read-props
     */
    private static function redeclaresBuilderMethod(string $builderClass, array $chainNames, Codebase $codebase): bool
    {
        try {
            $stock = $codebase->classlike_storage_provider->get(Builder::class)->declaring_method_ids;
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return true;
        }

        $trusted = [];

        foreach ($stock as $declaring) {
            $trusted[$declaring->fq_class_name] = true;
        }

        return self::declaresUserlandMethod($builderClass, null, [...\array_keys($stock), ...$chainNames], \array_keys($trusted), $codebase);
    }

    /**
     * Over-approximation: does a class of the model's lineage (the class, its parents, every trait)
     * carry a scope attribute, or does its file mention a global-scope registration? A global scope can
     * join, re-point FROM, or add a select, and models are not booted during analysis, so the registered
     * scopes cannot be read. Laravel's own Eloquent files are skipped: the one scope they register
     * (`SoftDeletingScope`) adds a where. A scope registered from outside the lineage (a service
     * provider, a middleware) is not seen.
     *
     * @param class-string<Model> $modelClass
     */
    private static function mayRegisterGlobalScope(string $modelClass, Codebase $codebase): bool
    {
        $eloquentRoot = \dirname((string) (new \ReflectionClass(Model::class))->getFileName()) . \DIRECTORY_SEPARATOR;
        $parents = \class_parents($modelClass);

        foreach ([$modelClass, ...\array_values($parents === false ? [] : $parents), ...\array_values(\class_uses_recursive($modelClass))] as $class) {
            if (!\class_exists($class) && !\trait_exists($class)) {
                return true;
            }

            $file = (new \ReflectionClass($class))->getFileName();

            if ($file !== false && \str_starts_with($file, $eloquentRoot)) {
                continue;
            }

            if (self::hasScopeAttribute($class, $codebase)) {
                return true;
            }

            $contents = $file === false ? false : \file_get_contents($file);

            if ($contents === false || \stripos($contents, 'addGlobalScope') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does `$class` carry `#[ScopedBy]` or an attribute extending it (Laravel 13 reads them with
     * `IS_INSTANCEOF`)? Read from Psalm's storage: runtime reflection with `IS_INSTANCEOF` loads every
     * attribute class, and one that raises a deprecation on load crashes the analysis. Missing storage
     * counts as a scope attribute.
     *
     * @psalm-capabilities read-props
     */
    private static function hasScopeAttribute(string $class, Codebase $codebase): bool
    {
        $storage = $codebase->classlike_storage_provider;

        try {
            foreach ($storage->get($class)->attributes as $attribute) {
                if (
                    \strcasecmp($attribute->fq_class_name, ScopedBy::class) === 0
                    || isset($storage->get($attribute->fq_class_name)->parent_classes[\strtolower(ScopedBy::class)])
                ) {
                    return true;
                }
            }
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return true;
        }

        return false;
    }

    /**
     * `Builder::withGlobalScope()` calls `$scope->extend($this)` at registration, and what it adds (a join,
     * a select, a macro) outlives `withoutGlobalScopes()`. Laravel's `SoftDeletingScope::extend()` only
     * registers its own macros. Read once per run from every class Psalm holds, so an unpopulated class
     * leaves the fact unproven.
     */
    private static function scopesNeverExtend(): bool
    {
        if (self::$scopesNeverExtend !== null) {
            return self::$scopesNeverExtend;
        }

        foreach (ClassLikeStorageProvider::getAll() as $storage) {
            if (self::scopeMayExtendBuilder($storage)) {
                return self::$scopesNeverExtend = false;
            }
        }

        return self::$scopesNeverExtend = true;
    }

    /**
     * Does this class possibly change the builder in `extend()` at scope registration? Only Laravel's own
     * `SoftDeletingScope` is trusted, by its own name: a subclass inherits `extend()`, which calls methods
     * (`addRestore()`, ...) the subclass may override to join or select.
     *
     * @psalm-mutation-free
     */
    public static function scopeMayExtendBuilder(ClassLikeStorage $storage): bool
    {
        if (!$storage->populated) {
            return true;
        }

        return isset($storage->declaring_method_ids['extend'])
            && !$storage->is_interface
            && isset($storage->class_implements[\strtolower(Scope::class)])
            && \strcasecmp($storage->name, SoftDeletingScope::class) !== 0;
    }

    public static function reset(): void
    {
        self::$scopesNeverExtend = null;
    }

    /**
     * Resolve the model an instance call fills plus the receiver class to name in the message, or null
     * when the receiver is not unambiguously one Eloquent model. Every atomic of the type must be that
     * same model (the original Model path) or a Builder/relation projecting to that same model; a union
     * mixing in `null`, another type, a different model, or a Model with a Builder/relation is skipped
     * to avoid a false positive. Mirrors {@see ImplicitQueryBuilderCallHandler}.
     *
     * @return array{class-string<Model>, string}|null
     */
    private static function instanceReceiver(MethodCall $expr, string $lowerMethod, AfterExpressionAnalysisEvent $event, Codebase $codebase): ?array
    {
        $receiverType = $event->getStatementsSource()->getNodeTypeProvider()->getType($expr->var);

        if (!$receiverType instanceof Union) {
            return null;
        }

        $modelClass = null;
        $receiverClass = '';
        $isModelReceiver = null;

        foreach ($receiverType->getAtomicTypes() as $atomicType) {
            if (!$atomicType instanceof TNamedObject) {
                return null;
            }

            if (self::isModelSubclass($atomicType->value, $codebase)) {
                $isModel = true;
                $candidate = isset(self::MASS_ASSIGNMENT_METHODS[$lowerMethod]) ? $atomicType->value : null;
            } else {
                $isModel = false;
                $candidate = self::creationReceiverModel($atomicType, $lowerMethod, $codebase);
            }

            if (
                $candidate === null
                || ($isModelReceiver !== null && $isModelReceiver !== $isModel)
                || ($modelClass !== null && $modelClass !== $candidate)
            ) {
                return null;
            }

            $modelClass = $candidate;
            $isModelReceiver = $isModel;
            $receiverClass = $receiverClass === '' ? $atomicType->value : $receiverClass;
        }

        return [$modelClass, $receiverClass];
    }

    /**
     * The model a Builder/relation receiver fills through `$lowerMethod`, projected from the receiver's
     * `TModel` / `TRelatedModel` ({@see CREATION_RECEIVERS}). Null (decline) when the method is not on
     * that receiver's allowlist, the receiver is an intersection, or the template does not project to
     * exactly one concrete Model subclass (unresolved template, `Model` itself, union, no `@extends`).
     *
     * @return class-string<Model>|null
     * @psalm-capabilities read-props
     */
    private static function creationReceiverModel(TNamedObject $atomic, string $lowerMethod, Codebase $codebase): ?string
    {
        if ($atomic->extra_types !== []) {
            return null;
        }

        foreach (self::CREATION_RECEIVERS as $ancestor => [$template, $methods, $hooks]) {
            if (!isset($methods[$lowerMethod]) || !ClassLineage::isA($codebase, $atomic->value, $ancestor)) {
                continue;
            }

            /** @var list<lowercase-string> $scanned the allowlist keys and hooks are lowercase literals */
            $scanned = [...\array_keys($methods), ...$hooks];

            if (self::declaresUserlandMethod($atomic->value, $lowerMethod, $scanned, self::TRUSTED_RECEIVER_DECLARERS, $codebase)) {
                return null;
            }

            $projected = ClassLineage::projectedTemplate($codebase, $atomic, $ancestor, $template);

            // `Builder<Post&ChildPost>`: the strict-one-model check reads only the head class, which
            // would validate against Post's columns while the call fills the narrower type.
            foreach ($projected?->getAtomicTypes() ?? [] as $projectedAtomic) {
                if ($projectedAtomic instanceof TNamedObject && $projectedAtomic->extra_types !== []) {
                    return null;
                }
            }

            $modelClass = ModelPropertyResolver::extractExactlyOneModelFromUnion($projected, $codebase);

            // The unbound `Model` is what a generic bound degrades to, never a model whose columns exist.
            return $modelClass === Model::class ? null : $modelClass;
        }

        return null;
    }

    /**
     * Does a class outside `$trusted` declare something the call runs through? A userland override may map
     * or admit keys (`headline` → `title`, a virtual `custom_fields`), and Laravel's `createQuietly()` /
     * `createManyQuietly()` go through `create()` / `createMany()`, so one override makes every scanned
     * name's keys unprovable. Unreadable storage declines too.
     *
     * @param class-string           $class
     * @param list<lowercase-string> $scanned  methods (and delegated hooks) to scan
     * @param list<string>           $trusted  exact declaring classes (never a namespace prefix)
     * @psalm-capabilities read-props
     */
    private static function declaresUserlandMethod(string $class, ?string $calledMethod, array $scanned, array $trusted, Codebase $codebase): bool
    {
        try {
            $storage = $codebase->classlike_storage_provider->get($class);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return true;
        }

        $declaringClasses = [];

        foreach ($scanned as $method) {
            $declaring = $storage->declaring_method_ids[$method] ?? null;

            if ($declaring instanceof MethodIdentifier) {
                $declaringClasses[$method] = $declaring->fq_class_name;
            }
        }

        return self::hasUserlandOverride($calledMethod, $declaringClasses, $scanned, $trusted);
    }

    /**
     * Pure verdict over the declaring class of each scanned method. A given called method must be declared
     * by a trusted class (else its behaviour is unknown: decline). Every other scanned name declines only
     * when it IS declared by an untrusted class; an absent name is ignored — BelongsToMany's `*Quietly`
     * methods do not exist at the Laravel 12.x floor, and their absence is not an override.
     *
     * @param array<lowercase-string, string> $declaringClasses declaring class per scanned name; absent names omitted
     * @param list<lowercase-string>          $scanned
     * @param list<string>                    $trusted
     *
     * @psalm-pure
     */
    public static function hasUserlandOverride(?string $calledMethod, array $declaringClasses, array $scanned, array $trusted): bool
    {
        if ($calledMethod !== null && !self::isTrustedDeclarer($declaringClasses[$calledMethod] ?? null, $trusted)) {
            return true;
        }

        foreach ($scanned as $method) {
            if (isset($declaringClasses[$method]) && !self::isTrustedDeclarer($declaringClasses[$method], $trusted)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $trusted
     *
     * @psalm-pure
     */
    private static function isTrustedDeclarer(?string $declaringClass, array $trusted): bool
    {
        if ($declaringClass === null) {
            return false;
        }

        foreach ($trusted as $class) {
            if (\strcasecmp($class, $declaringClass) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @psalm-assert-if-true class-string<Model> $className
     */
    private static function isModelSubclass(string $className, Codebase $codebase): bool
    {
        if ($className === Model::class) {
            return true;
        }

        if (!$codebase->classExists($className)) {
            return false;
        }

        // classExtends throws InvalidArgumentException on missing/aliased storage and
        // UnpopulatedClasslikeException when storage exists but is not populated yet. Either way the
        // subclass link can't be proven → treat as not a model. Mirrors ImplicitQueryBuilderCallHandler.
        try {
            return $codebase->classExtends($className, Model::class);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return false;
        }
    }

    /** @psalm-pure */
    private static function shortClassName(string $fqcn): string
    {
        $pos = \strrpos($fqcn, '\\');

        return $pos !== false ? \substr($fqcn, $pos + 1) : $fqcn;
    }
}
