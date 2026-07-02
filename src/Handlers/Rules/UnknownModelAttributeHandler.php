<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use Illuminate\Database\Eloquent\Model;
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
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\EloquentModelMethods;
use Psalm\LaravelPlugin\Issues\UnknownModelAttribute;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Always-on rule (#699): flags a literal array key passed to an Eloquent mass-assignment method that
 * matches no known attribute of the model — the typo `User::create(['nmae' => ...])`. Recognized =
 * {@see ModelMetadata::knownProperties()} ∪ `$fillable` ∪ `@property` pseudo-properties, matched
 * case/separator-insensitively (a JSON-path key by its base column). Self-silences when the model has
 * no column schema, on non-literal arrays/keys, and on ambiguous (non-single-model) receivers, so an
 * always-on rule never floods. Per-expression hook like {@see ModelMakeHandler}; cheap AST rejects first.
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

        if (!isset(self::MASS_ASSIGNMENT_METHODS[\strtolower($expr->name->name)])) {
            return null;
        }

        // The attribute map is the first positional argument. Bail on a first-class callable,
        // argument unpacking, a named argument for another parameter, or a non-literal array —
        // none expose enumerable literal keys.
        $arg = $expr->args[0] ?? null;

        if (!$arg instanceof Arg || $arg->unpack) {
            return null;
        }

        if ($arg->name instanceof Identifier && $arg->name->name !== 'attributes') {
            return null;
        }

        $array = $arg->value;

        if (!$array instanceof Array_) {
            return null;
        }

        $codebase = $event->getCodebase();

        $modelClass = $expr instanceof StaticCall
            ? self::staticReceiverModel($expr, $codebase)
            : self::instanceReceiverModel($expr, $event, $codebase);

        if ($modelClass === null) {
            return null;
        }

        $metadata = ModelMetadataRegistry::for($modelClass);

        if (!$metadata instanceof ModelMetadata) {
            return null;
        }

        // Completeness gate: the unknown-key verdict is only sound when the model's columns are
        // known. With migrations disabled (or the table unparsed) schema() is empty, so the known
        // set lacks its column origins and every real column would look unknown — skip the model.
        if ($metadata->schema()->all() === []) {
            return null;
        }

        // Collect the literal string keys; positional (list-style) and dynamic keys carry no
        // property name to validate and are dropped.
        $rawKeys = [];

        foreach ($array->items as $item) {
            // $item is null only for skipped destructuring slots (`[, $x]`), never in a value array.
            $key = $item?->key;

            if ($key instanceof String_) {
                $rawKeys[] = $key->value;
            }
        }

        $unknown = self::unknownKeys(self::allowedKeys($metadata, $modelClass, $codebase), $rawKeys);

        if ($unknown === []) {
            return null;
        }

        // Re-walk the items to emit at each offending key node (precise location), keyed by the
        // verdict above. A duplicated unknown key is reported at each of its occurrences.
        $unknownSet = \array_fill_keys($unknown, true);
        $shortName = self::shortClassName($modelClass);
        $methodName = $expr->name->name;

        foreach ($array->items as $item) {
            $key = $item?->key;

            if (!$key instanceof String_ || !isset($unknownSet[$key->value])) {
                continue;
            }

            IssueBuffer::accepts(
                new UnknownModelAttribute(
                    "{$shortName}::{$methodName}() is assigned an unknown attribute '{$key->value}'. "
                    . "It matches no column, cast, accessor, relation, \$appends entry, or @property "
                    . "of {$shortName} — check for a typo.",
                    new CodeLocation($event->getStatementsSource(), $key),
                ),
                $event->getStatementsSource()->getSuppressedIssues(),
            );
        }

        return null;
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
     * (resolvable) Eloquent model. Mirrors {@see ImplicitQueryBuilderCallHandler}.
     *
     * @return class-string<Model>|null
     */
    private static function staticReceiverModel(StaticCall $expr, Codebase $codebase): ?string
    {
        // Only named class references (`User::`, and `self`/`static`/`parent` which the name
        // resolver rewrites to a concrete FQCN); dynamic `$class::create()` is not known here.
        if (!$expr->class instanceof Name) {
            return null;
        }

        $className = $expr->class->getAttribute('resolvedName');

        if (!\is_string($className) || !self::isModelSubclass($className, $codebase)) {
            return null;
        }

        return $className;
    }

    /**
     * Resolve the model FQCN of an instance call's receiver, or null when the receiver type is not
     * unambiguously a single Eloquent model. Every atomic of the type must be that same model; a
     * union mixing in a Builder/Relation/Collection, `null`, or a different model is skipped to avoid
     * a false positive. Mirrors {@see ImplicitQueryBuilderCallHandler}.
     *
     * @return class-string<Model>|null
     */
    private static function instanceReceiverModel(MethodCall $expr, AfterExpressionAnalysisEvent $event, Codebase $codebase): ?string
    {
        $receiverType = $event->getStatementsSource()->getNodeTypeProvider()->getType($expr->var);

        if (!$receiverType instanceof Union) {
            return null;
        }

        $modelClass = null;

        foreach ($receiverType->getAtomicTypes() as $atomicType) {
            if (!$atomicType instanceof TNamedObject || !self::isModelSubclass($atomicType->value, $codebase)) {
                return null;
            }

            if ($modelClass === null) {
                $modelClass = $atomicType->value;
            } elseif ($modelClass !== $atomicType->value) {
                return null;
            }
        }

        return $modelClass;
    }

    /**
     * @psalm-assert-if-true class-string<Model> $className
     *
     * @psalm-external-mutation-free
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
