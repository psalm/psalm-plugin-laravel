<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use PhpParser;
use Psalm\Codebase;
use Psalm\Internal\MethodIdentifier;

/**
 * AST-parses a model's relationship method body to extract the related model class
 * and the relationship factory method name, without invoking the method.
 *
 * This enables property type resolution for relationship accessors even when the
 * return type lacks generic annotations. For example, given:
 *
 *   public function vault(): BelongsTo { return $this->belongsTo(Vault::class); }
 *
 * This parser extracts both "belongsTo" (factory method) and "App\Models\Vault" (related model).
 *
 * Handles:
 * - Direct returns: return $this->belongsTo(Vault::class)
 * - Chained methods: return $this->belongsTo(Vault::class)->withDefault()
 * - No declared return type: public function image() { return $this->morphOne(Image::class, 'imageable'); }
 *
 * @internal
 */
final class RelationMethodParser
{
    /**
     * Parsed result: the relationship factory method and optionally the related model FQCN.
     *
     * relatedModel is null when:
     * - The relation is polymorphic (morphTo) — the related type is not statically determinable
     * - The first argument could not be statically resolved (e.g. a variable or method call)
     *
     * @var array<string, ?array{relationClass: class-string<Relation>, relatedModel: ?string}>
     */
    private static array $cache = [];

    /**
     * Maps HasRelationships factory method names to their corresponding Relation class FQCNs.
     *
     * @var array<string, class-string<Relation>>
     */
    private const FACTORY_TO_RELATION = [
        'hasone' => HasOne::class,
        'hasmany' => HasMany::class,
        'hasonethrough' => HasOneThrough::class,
        'hasmanythrough' => HasManyThrough::class,
        'belongsto' => BelongsTo::class,
        'belongstomany' => BelongsToMany::class,
        'morphone' => MorphOne::class,
        'morphmany' => MorphMany::class,
        'morphto' => MorphTo::class,
        'morphtomany' => MorphToMany::class,
        'morphedbymany' => MorphToMany::class,
    ];

    /**
     * Parse a relationship method body and extract the relation class and related model.
     *
     * @return ?array{relationClass: class-string<Relation>, relatedModel: ?string}
     *         null if the method cannot be parsed as a relationship method.
     *         relatedModel is null when polymorphic (morphTo) or when the first argument
     *         could not be statically resolved.
     */
    public static function parse(Codebase $codebase, string $className, string $methodName): ?array
    {
        $cacheKey = $className . '::' . $methodName;

        if (\array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $result = self::doParse($codebase, $className, $methodName);
        self::$cache[$cacheKey] = $result;

        return $result;
    }

    /**
     * @return ?array{relationClass: class-string<Relation>, relatedModel: ?string}
     */
    private static function doParse(Codebase $codebase, string $className, string $methodName): ?array
    {
        $methodId = $className . '::' . $methodName;

        // No methodExists() guard needed — all call sites in ModelRelationshipPropertyHandler
        // already verify method existence. The try/catch below handles missing storage safely.
        try {
            $methodStorage = $codebase->methods->getStorage(
                MethodIdentifier::wrap($methodId),
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return null;
        }

        $location = $methodStorage->location;
        if (!$location instanceof \Psalm\CodeLocation) {
            return null;
        }

        try {
            $stmts = $codebase->getStatementsForFile($location->file_path);
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return null;
        }

        $classMethod = self::findMethod($stmts, $className, $methodName);
        if (!$classMethod instanceof PhpParser\Node\Stmt\ClassMethod || $classMethod->stmts === null) {
            return null;
        }

        return self::parseMethodBody($classMethod->stmts);
    }

    /**
     * Walk the method body to find a return statement containing a relationship factory call
     * like $this->belongsTo(Vault::class).
     *
     * @param array<array-key, PhpParser\Node\Stmt> $stmts
     * @return ?array{relationClass: class-string<Relation>, relatedModel: ?string}
     */
    private static function parseMethodBody(array $stmts): ?array
    {
        foreach ($stmts as $stmt) {
            if (!$stmt instanceof PhpParser\Node\Stmt\Return_ || !$stmt->expr instanceof PhpParser\Node\Expr) {
                continue;
            }

            return self::findRelationCallInExpr($stmt->expr);
        }

        return null;
    }

    /**
     * Recursively search an expression for a relationship factory method call.
     * Handles both direct calls and method chains.
     *
     * @return ?array{relationClass: class-string<Relation>, relatedModel: ?string}
     */
    private static function findRelationCallInExpr(PhpParser\Node\Expr $expr): ?array
    {
        if (!$expr instanceof PhpParser\Node\Expr\MethodCall) {
            return null;
        }

        // Check if this is a relationship factory call (e.g. $this->belongsTo(...))
        if ($expr->name instanceof PhpParser\Node\Identifier) {
            $lowerName = \strtolower($expr->name->name);
            $relationClass = self::FACTORY_TO_RELATION[$lowerName] ?? null;

            if ($relationClass !== null) {
                return [
                    'relationClass' => $relationClass,
                    'relatedModel' => self::extractClassStringArg($expr, $lowerName),
                ];
            }
        }

        // Not a relationship call — try the inner expression (unwrap chain).
        // e.g. for $this->belongsTo(X::class)->withDefault(), $expr->var is $this->belongsTo(X::class)
        return self::findRelationCallInExpr($expr->var);
    }

    /**
     * Extract the class-string first argument from a relationship factory call.
     *
     * morphTo() is special: it may have no arguments (Laravel infers the type from the method name),
     * or it may have string arguments rather than a class-string. Returns null for morphTo()
     * since the related model type is polymorphic and not statically determinable.
     */
    private static function extractClassStringArg(PhpParser\Node\Expr\MethodCall $call, string $lowerMethodName): ?string
    {
        // morphTo() doesn't take a class-string<Model> as first arg — the related type is polymorphic
        if ($lowerMethodName === 'morphto') {
            return null;
        }

        $args = $call->args;
        if ($args === [] || !$args[0] instanceof PhpParser\Node\Arg) {
            return null;
        }

        $firstArg = $args[0]->value;

        // Expect ClassName::class
        if (
            $firstArg instanceof PhpParser\Node\Expr\ClassConstFetch
            && $firstArg->class instanceof PhpParser\Node\Name
            && $firstArg->name instanceof PhpParser\Node\Identifier
            && $firstArg->name->name === 'class'
        ) {
            // Prefer the FQCN resolved by Psalm's name-resolution pass
            /** @var string|null $resolved */
            $resolved = $firstArg->class->getAttribute('resolvedName');
            if (\is_string($resolved)) {
                return $resolved;
            }

            return $firstArg->class->toString();
        }

        return null;
    }

    /**
     * Walk the AST (namespace → class → method) to find a specific method in a specific class.
     *
     * @param list<PhpParser\Node\Stmt> $stmts
     * @psalm-mutation-free
     */
    private static function findMethod(array $stmts, string $className, string $methodName): ?PhpParser\Node\Stmt\ClassMethod
    {
        $lowerMethodName = \strtolower($methodName);
        $lowerClassName = \strtolower($className);

        foreach ($stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\Namespace_) {
                $namespaceName = $stmt->name?->toString() ?? '';

                foreach ($stmt->stmts as $nsStmt) {
                    if (!$nsStmt instanceof PhpParser\Node\Stmt\Class_) {
                        continue;
                    }

                    $shortName = $nsStmt->name?->toString() ?? '';
                    $fqcn = $namespaceName !== '' ? $namespaceName . '\\' . $shortName : $shortName;

                    if (\strtolower($fqcn) !== $lowerClassName) {
                        continue;
                    }

                    return self::findMethodInClass($nsStmt, $lowerMethodName);
                }

                continue;
            }

            // Top-level class (no namespace)
            if (!$stmt instanceof PhpParser\Node\Stmt\Class_) {
                continue;
            }

            $shortName = $stmt->name?->toString() ?? '';
            if (\strtolower($shortName) !== $lowerClassName) {
                continue;
            }

            return self::findMethodInClass($stmt, $lowerMethodName);
        }

        return null;
    }

    /** @psalm-mutation-free */
    private static function findMethodInClass(PhpParser\Node\Stmt\Class_ $class, string $lowerMethodName): ?PhpParser\Node\Stmt\ClassMethod
    {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\ClassMethod && \strtolower($stmt->name->name) === $lowerMethodName) {
                return $stmt;
            }
        }

        return null;
    }
}
