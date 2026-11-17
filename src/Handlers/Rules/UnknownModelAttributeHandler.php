<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\GuardsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
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
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\EloquentModelMethods;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\ModelPropertyResolver;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\LaravelPlugin\Issues\UnknownModelAttribute;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\StatementsSource;
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
 *
 * #2339 adds the builder writes that name columns ({@see self::COLUMN_METHODS}: `upsert()`,
 * `withAttributes()`, `fillAndInsert*()`, `incrementEach()`). Their keys are raw column names, so the
 * same recognized set applies, and a qualified `table.column` name is never checked.
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
     * `make*` build an unsaved instance. The value is whether the receiver declares the method itself
     * (see {@see self::CREATION_RECEIVERS}).
     *
     * @var array<lowercase-string, bool>
     */
    private const MAP_CREATION_METHODS = [
        'make' => true,
        'create' => true,
        'createquietly' => true,
        'forcecreate' => true,
        'forcecreatequietly' => true,
    ];

    /**
     * Relation methods taking a list of attribute maps (`$records`).
     *
     * @var array<lowercase-string, bool>
     */
    private const LIST_CREATION_METHODS = [
        'makemany' => true,
        'createmany' => true,
        'createmanyquietly' => true,
        'forcecreatemany' => true,
        'forcecreatemanyquietly' => true,
    ];

    /**
     * One attribute map (`create`, `fill`), the record list of a `*Many` method, a single row or a list of
     * rows (`upsert`, `fillAndInsert`), or column names (`upsert()`'s `$uniqueBy` / `$update`,
     * `withAttributes()`).
     */
    private const ROW = 'row';
    private const LIST = 'list';
    private const ROWS = 'rows';
    private const NAMES = 'names';

    /**
     * Builder writes whose arguments name columns (the keys are raw column names, not fill input):
     * lowercase method => [parameter name, position, argument kind] for every argument checked. Model
     * receivers reach all of them (`__call` / `__callStatic` forwarding to the builder; `incrementEach*` are
     * the model's own since Laravel 13.3, the `*Quietly` twins since 13.20, earlier they forward); the receivers
     * that carry each are in {@see self::CREATION_RECEIVERS}. Parameter names are Laravel's, for named-argument
     * calls. The `fillAndInsert*` family and `fillForInsert()` need Laravel 12.6.
     *
     * @var array<lowercase-string, list<array{string, int<0, max>, string}>>
     */
    private const COLUMN_METHODS = [
        'upsert' => [['values', 0, self::ROWS], ['uniqueBy', 1, self::NAMES], ['update', 2, self::NAMES]],
        'withattributes' => [['attributes', 0, self::NAMES]],
        'fillandinsert' => [['values', 0, self::ROWS]],
        'fillandinsertorignore' => [['values', 0, self::ROWS]],
        'fillandinsertgetid' => [['values', 0, self::ROW]],
        'fillforinsert' => [['values', 0, self::ROWS]],
        'incrementeach' => [['columns', 0, self::ROW], ['extra', 1, self::ROW]],
        'decrementeach' => [['columns', 0, self::ROW], ['extra', 1, self::ROW]],
        'incrementeachquietly' => [['columns', 0, self::ROW], ['extra', 1, self::ROW]],
        'decrementeachquietly' => [['columns', 0, self::ROW], ['extra', 1, self::ROW]],
    ];

    /**
     * Column writes a builder runs against its model's table. Relations forward them to their related
     * model's builder (`Relation::__call`), or declare an equivalent that adds the foreign key (`upsert`);
     * either way the keys are the related model's columns. The value is `false`: a receiver may forward the
     * method instead of declaring it.
     *
     * @var array<lowercase-string, bool>
     */
    private const INSERT_COLUMN_METHODS = [
        'upsert' => false,
        'withattributes' => false,
        'fillandinsert' => false,
        'fillandinsertorignore' => false,
        'fillandinsertgetid' => false,
        'fillforinsert' => false,
    ];

    /**
     * `incrementEach` / `decrementEach` on a builder or a has-one/has-many relation. Not on `BelongsToMany`:
     * its query joins the pivot table, so a pivot column is a legitimate key the related model cannot vouch for.
     *
     * @var array<lowercase-string, bool>
     */
    private const INCREMENT_EACH_METHODS = [
        'incrementeach' => false,
        'decrementeach' => false,
    ];

    /**
     * Receiver class => [template carrying the filled model, methods that receiver checks (value: whether it
     * declares the method itself rather than forwarding it to the builder), delegated hooks those methods
     * run on the way to the model]. A userland override of a hook can rename keys like an override of a
     * listed method, so hooks join the override scan only. BelongsToMany declares only `create*`; its
     * `make`/`forceCreate` reach the Builder through `__call`, so they are not listed. Its second argument
     * is pivot data and is never inspected.
     *
     * @var array<class-string, array{string, array<lowercase-string, bool>, list<lowercase-string>}>
     */
    private const CREATION_RECEIVERS = [
        Builder::class => [
            'TModel',
            [...self::MAP_CREATION_METHODS, ...self::INSERT_COLUMN_METHODS, ...self::INCREMENT_EACH_METHODS],
            ['newmodelinstance'],
        ],
        HasOneOrMany::class => [
            'TRelatedModel',
            [
                ...self::MAP_CREATION_METHODS,
                ...self::LIST_CREATION_METHODS,
                ...self::INSERT_COLUMN_METHODS,
                ...self::INCREMENT_EACH_METHODS,
            ],
            ['setforeignattributesforcreate'],
        ],
        BelongsToMany::class => [
            'TRelatedModel',
            [
                'create' => true,
                'createquietly' => true,
                'createmany' => true,
                'createmanyquietly' => true,
                ...self::INSERT_COLUMN_METHODS,
            ],
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

        if (
            !isset(self::MASS_ASSIGNMENT_METHODS[$lowerMethod])
            && !isset(self::MAP_CREATION_METHODS[$lowerMethod])
            && !isset(self::LIST_CREATION_METHODS[$lowerMethod])
            && !isset(self::COLUMN_METHODS[$lowerMethod])
        ) {
            return null;
        }

        // Literal string names the call hands to the model: the attribute map (or, for the `*Many` list
        // methods, the records list) is the first positional argument, or the one named `attributes` /
        // `records` wherever it sits. Bail on a first-class callable, argument unpacking, or a non-literal
        // array — none expose enumerable literal keys.
        $targets = self::literalTargets($expr->args, $lowerMethod);

        if ($targets === []) {
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
            $modelClass = self::isModelMethod($lowerMethod)
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

        // A Model receiver reaching a builder write through `__call` is shadowed by a method of the same name
        // declared on the model itself, so that name joins the scan.
        $modelScan = $receiverClass === $modelClass && isset(self::COLUMN_METHODS[$lowerMethod])
            ? [...self::MODEL_FILL_METHODS, $lowerMethod]
            : self::MODEL_FILL_METHODS;

        if (self::declaresUserlandMethod($modelClass, null, $modelScan, self::TRUSTED_MODEL_DECLARERS, $codebase)) {
            return null;
        }

        $metadata = ModelMetadataRegistry::for($modelClass);

        if (!$metadata instanceof ModelMetadata) {
            return null;
        }

        if (
            !$metadata->isComplete(
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

        $allowedKeys = self::allowedKeys($metadata, $modelClass, $codebase);
        $receiverName = self::shortClassName($receiverClass);
        $modelName = self::shortClassName($modelClass);
        // Column-reference arguments take `table.column`, which names a column the model's own set cannot vouch for.
        $isColumnWrite = isset(self::COLUMN_METHODS[$lowerMethod]);

        foreach ($targets as [$key, $kind]) {
            // A duplicated unknown key is reported at each of its occurrences, at the key node.
            if (
                ($isColumnWrite && \str_contains($key->value, '.'))
                || self::unknownKeys($allowedKeys, [$key->value]) === []
            ) {
                continue;
            }

            $verb = $kind === self::NAMES ? 'references' : 'is assigned';

            IssueBuffer::accepts(
                new UnknownModelAttribute(
                    "{$receiverName}::{$expr->name->name}() {$verb} an unknown attribute '{$key->value}'. "
                    . "It matches no column, cast, accessor, relation, \$appends entry, or @property "
                    . "of {$modelName} — check for a typo.",
                    new CodeLocation($event->getStatementsSource(), $key),
                ),
                $event->getStatementsSource()->getSuppressedIssues(),
            );
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
     * The argument bound to `$paramName` / `$position`: the positional argument at `$position`, else the
     * argument named `$paramName` wherever it sits (`create(joining: [...], attributes: [...])`). Null when
     * there is none, an unpacked argument precedes or sits at the position (so the position is unprovable),
     * or the call is a first-class callable.
     *
     * @param array<array-key, \PhpParser\Node> $args
     *
     * @psalm-capabilities read-props
     */
    private static function dataArgument(array $args, string $paramName, int $position): ?Arg
    {
        foreach (\array_values($args) as $index => $arg) {
            if (!$arg instanceof Arg) {
                return null;
            }

            if ($arg->unpack) {
                if ($index <= $position) {
                    return null;
                }

                continue;
            }

            if ($arg->name instanceof Identifier ? $arg->name->name === $paramName : $index === $position) {
                return $arg;
            }
        }

        return null;
    }

    /**
     * The string-literal nodes of the call's checked arguments, each with the kind of argument it came from.
     * AST-only, so the caller can bail before any receiver or registry work.
     *
     * @param array<array-key, \PhpParser\Node> $args
     *
     * @return list<array{String_, string}>
     *
     * @psalm-capabilities read-props
     */
    private static function literalTargets(array $args, string $lowerMethod): array
    {
        $specs = self::COLUMN_METHODS[$lowerMethod]
            ?? [isset(self::LIST_CREATION_METHODS[$lowerMethod]) ? ['records', 0, self::LIST] : ['attributes', 0, self::ROW]];

        $targets = [];

        foreach ($specs as [$paramName, $position, $kind]) {
            $value = self::dataArgument($args, $paramName, $position)?->value;

            if ($value === null) {
                continue;
            }

            foreach (self::literalNames($value, $kind) as $node) {
                $targets[] = [$node, $kind];
            }
        }

        return $targets;
    }

    /**
     * String-literal nodes naming an attribute in one argument, by the argument's kind. Empty when the
     * argument is not a literal of that shape or carries unprovable keys.
     *
     * @return list<String_>
     *
     * @psalm-capabilities read-props
     */
    private static function literalNames(Expr $value, string $kind): array
    {
        if ($value instanceof String_) {
            return $kind === self::NAMES ? [$value] : [];
        }

        if (!$value instanceof Array_) {
            return [];
        }

        return match ($kind) {
            self::ROW => self::rowKeys($value),
            self::LIST => self::listKeys($value),
            self::ROWS => self::rowsKeys($value),
            default => self::nameEntries($value),
        };
    }

    /**
     * The literal string keys of one attribute map. Positional and dynamic keys carry no name and are
     * dropped; a spread leaves the remaining literal keys checkable.
     *
     * @return list<String_>
     *
     * @psalm-capabilities read-props
     */
    private static function rowKeys(Array_ $map): array
    {
        $keys = [];

        foreach ($map->items as $item) {
            if ($item->key instanceof String_) {
                $keys[] = $item->key;
            }
        }

        return $keys;
    }

    /**
     * The literal keys of every literal map in a list of records. Only a pure keyless list is checked: a
     * spread or an explicit outer key can merge or overwrite records, so which maps are really used is not
     * statically known. Non-literal items carry no keys.
     *
     * @return list<String_>
     *
     * @psalm-capabilities read-props
     */
    private static function listKeys(Array_ $records): array
    {
        $keys = [];

        foreach ($records->items as $item) {
            if ($item->unpack || $item->key !== null) {
                return [];
            }

            if ($item->value instanceof Array_) {
                \array_push($keys, ...self::rowKeys($item->value));
            }
        }

        return $keys;
    }

    /**
     * A single row or a list of rows, told apart the way Laravel does (`is_array(array_first($values))`): a
     * first entry that is a literal array, or has no key, makes it a list of rows.
     *
     * @return list<String_>
     *
     * @psalm-capabilities read-props
     */
    private static function rowsKeys(Array_ $values): array
    {
        $first = $values->items[0] ?? null;

        if ($first === null || $first->unpack) {
            return [];
        }

        return !$first->value instanceof Array_ && $first->key !== null
            ? self::rowKeys($values)
            : self::listKeys($values);
    }

    /**
     * Column names in an array: a string key (`['views' => 1]`) or a keyless string value (`['id', 'email']`).
     *
     * @return list<String_>
     *
     * @psalm-capabilities read-props
     */
    private static function nameEntries(Array_ $names): array
    {
        $entries = [];

        foreach ($names->items as $item) {
            if ($item->key instanceof String_) {
                $entries[] = $item->key;
            } elseif ($item->key === null && !$item->unpack && $item->value instanceof String_) {
                $entries[] = $item->value;
            }
        }

        return $entries;
    }

    /**
     * Does a Model receiver (static or instance) reach this method? Either its own mass-assignment method
     * or a builder write reached through `__call` / `__callStatic` forwarding (or the model's own `incrementEach`).
     *
     * @psalm-pure
     */
    private static function isModelMethod(string $lowerMethod): bool
    {
        return isset(self::MASS_ASSIGNMENT_METHODS[$lowerMethod]) || isset(self::COLUMN_METHODS[$lowerMethod]);
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
                $candidate = self::isModelMethod($lowerMethod) ? $atomicType->value : null;
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

            // `true`: the receiver declares the method itself, so a declaration outside the trusted framework
            // classes (or none at all) leaves its behaviour unknown. `false`: it may be forwarded to the
            // builder through `__call` (absent from the receiver), so only an untrusted declaration declines.
            $calledMethod = $methods[$lowerMethod] ? $lowerMethod : null;

            if (self::declaresUserlandMethod($atomic->value, $calledMethod, $scanned, self::TRUSTED_RECEIVER_DECLARERS, $codebase)) {
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
     * @param list<class-string>     $trusted
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
     * @param list<class-string>              $trusted
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
     * @param list<class-string> $trusted
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
