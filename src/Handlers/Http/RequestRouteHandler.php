<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Http;

use Illuminate\Http\Request;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;

/**
 * Narrows the return type of {@see Request::route($param)} when the concrete caller
 * is a FormRequest subclass annotated with `@psalm-route-model`.
 *
 * Without this handler, `$request->route('station')` returns `Model|string|null`,
 * causing `InvalidPropertyFetch` when chaining property access like `->id`.
 *
 * Usage: annotate your FormRequest subclass with one `@psalm-route-model` line per
 * route parameter, specifying the parameter name and the bound model FQCN:
 *
 *   @psalm-route-model station \App\Models\RadioStation
 *
 * Multiple parameters are supported — one annotation line per param. Annotations are
 * inherited from parent FormRequest classes (child-class declarations take precedence).
 *
 * @internal
 */
final class RequestRouteHandler implements MethodReturnTypeProviderInterface
{
    /**
     * Cache of resolved route-model maps per FormRequest class.
     *
     * Keyed by lowercase FQCN. A null value means the class has no annotations.
     *
     * @var array<string, array<string, class-string>|null>
     */
    private static array $cache = [];

    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Request::class];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'route') {
            return null;
        }

        $callArgs = $event->getCallArgs();

        // No param arg → returns Route (handled by stub)
        if ($callArgs === []) {
            return null;
        }

        // Require a string literal param name to know which model to look up
        $source = $event->getSource();
        $firstArgType = $source->getNodeTypeProvider()->getType($callArgs[0]->value);

        if (!$firstArgType instanceof Union || !$firstArgType->isSingleStringLiteral()) {
            return null;
        }

        $paramName = $firstArgType->getSingleStringLiteral()->value;

        // Get the concrete caller class (e.g. RadioStationUpdateRequest, not just Request)
        $calledClass = $event->getCalledFqClasslikeName();

        if ($calledClass === null) {
            return null;
        }

        $routeModels = self::getRouteModels($calledClass);

        if ($routeModels === null || !isset($routeModels[$paramName])) {
            return null;
        }

        return new Union([
            new TNamedObject($routeModels[$paramName]),
            new TNull(),
        ]);
    }

    /**
     * Walk the class hierarchy and collect all `@psalm-route-model` annotations.
     *
     * Child-class declarations take precedence over parent-class declarations
     * (first occurrence of a param name wins when iterating child → parent).
     *
     * @return array<string, class-string>|null  param => model FQCN, or null when none found
     */
    private static function getRouteModels(string $className): ?array
    {
        $cacheKey = \strtolower($className);

        if (\array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        try {
            $codebase = ProjectAnalyzer::getInstance()->getCodebase();
        } catch (\RuntimeException) {
            return self::$cache[$cacheKey] = null;
        }

        $result = [];
        $currentClass = \strtolower($className);

        while ($currentClass !== '') {
            try {
                $storage = $codebase->classlike_storage_provider->get($currentClass);
            } catch (\InvalidArgumentException) {
                break;
            }

            $filePath = $storage->location?->file_path;

            if ($filePath !== null) {
                $annotations = self::parseRouteModelAnnotations($codebase, $filePath, $storage->name);

                // Child class takes precedence — only store a param when not yet seen
                foreach ($annotations as $param => $model) {
                    $result[$param] ??= $model;
                }
            }

            $currentClass = \strtolower($storage->parent_class ?? '');
        }

        return self::$cache[$cacheKey] = $result !== [] ? $result : null;
    }

    /**
     * Parse `@psalm-route-model` annotations from a class declaration's PHPDoc.
     *
     * @return array<string, class-string>
     */
    private static function parseRouteModelAnnotations(
        Codebase $codebase,
        string $filePath,
        string $className,
    ): array {
        try {
            $statements = $codebase->getStatementsForFile($filePath);
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return [];
        }

        foreach ($statements as $stmt) {
            $docComment = self::findClassDocComment($stmt, $className, '');

            if ($docComment !== null) {
                return self::extractRouteModelAnnotations($codebase, $docComment);
            }
        }

        return [];
    }

    /**
     * Recursively walk an AST statement to find a class declaration's PHPDoc comment.
     *
     * @param \PhpParser\Node\Stmt $stmt
     */
    private static function findClassDocComment(
        \PhpParser\Node\Stmt $stmt,
        string $className,
        string $namespace,
    ): ?string {
        if ($stmt instanceof Namespace_) {
            $namespaceName = $stmt->name?->toString() ?? '';

            foreach ($stmt->stmts as $child) {
                $result = self::findClassDocComment($child, $className, $namespaceName);

                if ($result !== null) {
                    return $result;
                }
            }

            return null;
        }

        if (!$stmt instanceof Class_) {
            return null;
        }

        $shortName = $stmt->name?->toString() ?? '';
        $fqcn = $namespace !== '' ? $namespace . '\\' . $shortName : $shortName;

        if (\strtolower($fqcn) !== \strtolower($className)) {
            return null;
        }

        $docComment = $stmt->getDocComment();

        return $docComment ? $docComment->getText() : null;
    }

    /**
     * Extract `@psalm-route-model <param> <ModelClass>` entries from a PHPDoc string.
     *
     * Only entries where the declared model class is known to the codebase are included.
     *
     * @return array<string, class-string>
     */
    private static function extractRouteModelAnnotations(Codebase $codebase, string $docComment): array
    {
        $result = [];

        \preg_match_all(
            '/@psalm-route-model\s+([A-Za-z_][A-Za-z0-9_]*)\s+(\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)/',
            $docComment,
            $matches,
            \PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $paramName = $match[1];
            $modelClass = \ltrim($match[2], '\\');

            if (!$codebase->classExists($modelClass)) {
                continue;
            }

            /** @var class-string $modelClass */
            $result[$paramName] = $modelClass;
        }

        return $result;
    }
}
