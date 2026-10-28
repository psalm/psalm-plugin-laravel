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
     * Caveat: Psalm's declaring-method map is wrong under trait `insteadof` (vimeo/psalm#12113), so such models may be misjudged.
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
     * The argument carrying the attribute map / records list: a named argument matching `$paramName`
     * wherever it sits, else the first positional argument. Null when there is none, it is unpacked, or
     * the call is a first-class callable.
     *
     * @param array<array-key, \PhpParser\Node> $args
     *
     * @psalm-capabilities read-props
     */
    private static function dataArgument(array $args, string $paramName): ?Arg
    {
        $first = $args[0] ?? null;

        if (!$first instanceof Arg) {
            return null;
        }

        if (!$first->name instanceof Identifier) {
            return $first->unpack ? null : $first;
        }

        foreach ($args as $arg) {
            if ($arg instanceof Arg && !$arg->unpack && $arg->name?->name === $paramName) {
                return $arg;
            }
        }

        return null;
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
