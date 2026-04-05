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
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Extracts relationship metadata from a model method's AST body and docblock annotations,
 * without invoking the method.
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
 * - Docblock generics for morphTo: @return MorphTo<User|Post, $this>
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

    /** @var list<string> Types that should not be resolved as class names in generic params */
    private const NON_CLASS_TYPES = [
        'static', 'self', 'parent', 'null', 'int', 'string', 'bool', 'float', 'mixed',
        'array', 'object', 'callable', 'iterable', 'void', 'never', 'true', 'false',
        'scalar', 'numeric', 'resource',
        // Deprecated aliases recognized by Psalm's TypeTokenizer
        'boolean', 'integer', 'double', 'real',
    ];

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
        $context = self::findClassMethodWithStatements($codebase, $className, $methodName);
        if ($context === null) {
            return null;
        }

        $classMethod = $context['classMethod'];
        if ($classMethod->stmts === null) {
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
     * Extract TRelatedModel from the method's docblock generic return type annotation.
     *
     * Used for morphTo relations where the related model can't be determined from the
     * factory call arguments but may be annotated via @return MorphTo<User|Post, $this>.
     *
     * When Psalm resolves $this in generic params, it may collapse the type to a
     * non-generic TNamedObject, losing the generic info. This method reads the raw
     * docblock to recover it.
     *
     * @return ?Union The related model type (e.g. User|Post), or null if not annotated
     */
    public static function extractDocblockRelatedModelType(Codebase $codebase, string $className, string $methodName): ?Union
    {
        $context = self::findClassMethodWithStatements($codebase, $className, $methodName);
        if ($context === null) {
            return null;
        }

        $docComment = $context['classMethod']->getDocComment();
        if (!$docComment instanceof \PhpParser\Comment\Doc) {
            return null;
        }

        // Extract the first generic param from @psalm-return (preferred), @phpstan-return, or @return
        $firstParam = self::extractFirstGenericParam($docComment->getText());
        if ($firstParam === null) {
            return null;
        }

        // Resolve short class names against the file's use statements
        $useMap = self::buildUseMap($context['fileStmts']);
        $namespace = self::extractNamespace($className);

        return self::resolveTypeNames($firstParam, $useMap, $namespace);
    }

    /**
     * Locate a ClassMethod node and its enclosing file statements.
     *
     * Shared by both parse() and extractDocblockRelatedModelType() to avoid
     * duplicating the method-storage → file-statements → findMethod sequence.
     *
     * @return ?array{classMethod: PhpParser\Node\Stmt\ClassMethod, fileStmts: list<PhpParser\Node\Stmt>}
     */
    private static function findClassMethodWithStatements(Codebase $codebase, string $className, string $methodName): ?array
    {
        $methodId = $className . '::' . $methodName;

        try {
            $methodStorage = $codebase->methods->getStorage(
                MethodIdentifier::wrap($methodId),
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            $codebase->progress->debug("Laravel plugin: could not get method storage for {$methodId}: {$e->getMessage()}\n");
            return null;
        }

        $location = $methodStorage->location;
        if (!$location instanceof \Psalm\CodeLocation) {
            return null;
        }

        try {
            $stmts = $codebase->getStatementsForFile($location->file_path);
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            $codebase->progress->debug("Laravel plugin: could not get statements for {$location->file_path}: {$e->getMessage()}\n");
            return null;
        }

        $classMethod = self::findMethod($stmts, $className, $methodName);
        if (!$classMethod instanceof PhpParser\Node\Stmt\ClassMethod) {
            return null;
        }

        return ['classMethod' => $classMethod, 'fileStmts' => $stmts];
    }

    /**
     * Extract the first generic type parameter from a docblock @return annotation.
     *
     * Checks @psalm-return, @phpstan-return, then @return (matching Psalm's priority).
     * e.g. "@psalm-return MorphTo<User|Post, $this>" → "User|Post"
     *
     * @psalm-pure
     */
    private static function extractFirstGenericParam(string $docblock): ?string
    {
        // Psalm's priority: @psalm-return > @phpstan-return > @return
        foreach (['@psalm-return', '@phpstan-return', '@return'] as $tag) {
            if (\preg_match('/' . $tag . '\s+\S+<([^,>]+)/', $docblock, $matches)) {
                return \trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * Build a map of short class name → FQCN from use statements in the file AST.
     *
     * Handles both regular use statements and group use statements (use App\Models\{User, Post}).
     *
     * @param list<PhpParser\Node\Stmt> $stmts
     * @return array<string, string> alias → FQCN
     */
    private static function buildUseMap(array $stmts): array
    {
        $map = [];

        foreach ($stmts as $stmt) {
            self::collectUseStatements($stmt, $map);

            // Also check inside namespace blocks
            if ($stmt instanceof PhpParser\Node\Stmt\Namespace_) {
                foreach ($stmt->stmts as $nsStmt) {
                    self::collectUseStatements($nsStmt, $map);
                }
            }
        }

        return $map;
    }

    /**
     * Collect use and group-use statements into the alias map.
     *
     * @param array<string, string> $map
     */
    private static function collectUseStatements(PhpParser\Node\Stmt $stmt, array &$map): void
    {
        // Only collect class imports (TYPE_NORMAL), skip use function/use const
        if ($stmt instanceof PhpParser\Node\Stmt\Use_ && $stmt->type === PhpParser\Node\Stmt\Use_::TYPE_NORMAL) {
            foreach ($stmt->uses as $use) {
                $alias = $use->alias?->toString() ?? $use->name->getLast();
                $map[$alias] = $use->name->toString();
            }
        } elseif ($stmt instanceof PhpParser\Node\Stmt\GroupUse) {
            $prefix = $stmt->prefix->toString();
            foreach ($stmt->uses as $use) {
                // Combine statement-level and item-level type via bitwise OR, matching
                // PhpParser's NameResolver. For "use App\Models\{User}" the GroupUse
                // has TYPE_UNKNOWN and items have TYPE_NORMAL. For "use function App\{foo}"
                // the GroupUse has TYPE_FUNCTION and items have TYPE_UNKNOWN.
                $type = $stmt->type | $use->type;
                if ($type === PhpParser\Node\Stmt\Use_::TYPE_FUNCTION
                    || $type === PhpParser\Node\Stmt\Use_::TYPE_CONSTANT
                ) {
                    continue;
                }

                $alias = $use->alias?->toString() ?? $use->name->getLast();
                $map[$alias] = $prefix . '\\' . $use->name->toString();
            }
        }
    }

    /**
     * Extract the namespace from a FQCN (everything before the last backslash).
     *
     * @psalm-pure
     */
    private static function extractNamespace(string $className): string
    {
        $lastSlash = \strrpos($className, '\\');

        return $lastSlash !== false ? \substr($className, 0, $lastSlash) : '';
    }

    /**
     * Resolve a pipe-separated type string (e.g. "User|Post") to a Psalm Union type.
     *
     * Each name is resolved against the use map, falling back to the current namespace.
     *
     * @param array<string, string> $useMap
     * @psalm-pure
     */
    private static function resolveTypeNames(string $typeString, array $useMap, string $namespace): ?Union
    {
        $names = \array_map(\trim(...), \explode('|', $typeString));
        $atomics = [];

        foreach ($names as $name) {
            // Skip non-class types ($this, static, self, scalar types)
            if ($name === '' || $name[0] === '$' || \in_array(\strtolower($name), self::NON_CLASS_TYPES, true)) {
                continue;
            }

            // Already fully qualified
            if ($name[0] === '\\') {
                $atomics[] = new TNamedObject(\ltrim($name, '\\'));
                continue;
            }

            // Check use map
            if (isset($useMap[$name])) {
                $atomics[] = new TNamedObject($useMap[$name]);
                continue;
            }

            // Fall back to current namespace
            $fqcn = $namespace !== '' ? $namespace . '\\' . $name : $name;
            $atomics[] = new TNamedObject($fqcn);
        }

        if ($atomics === []) {
            return null;
        }

        return new Union($atomics);
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
