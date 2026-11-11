<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\FileManipulation;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelMethodHandler;
use Psalm\LaravelPlugin\Internal\ModelCallGuard;
use Psalm\LaravelPlugin\Internal\PsalterFix;
use Psalm\LaravelPlugin\Issues\ModelMakeDiscouraged;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;

/**
 * Flags Model::make() calls and suggests using new Model() instead.
 *
 * Model::make() is forwarded through magic methods (__callStatic -> __call ->
 * forwardCallTo) to Builder::make(), which just creates a new instance via
 * newModelInstance(). Using the constructor directly is clearer and avoids
 * the indirection.
 *
 * Psalter fixes it: `Post::make($a)` becomes `new Post($a)`. The fix leaves the class name and the
 * arguments untouched and only inserts `new ` and removes `::make`. It is withheld (the issue is still
 * reported) for a first-class callable `Post::make(...)`; for any call in a trait or in a non-static closure or
 * arrow function (shared source, rebindable `$this`); for a named call in a non-static method whose `$this` is,
 * or may be, an instance of the model (PHP runs it as `$this->__call('make')` on the current instance); for a
 * model that customizes booting (`boot*()` methods, `#[ScopedBy]`); and for a model whose creation or dispatch
 * pipeline is customized (own constructor, `newInstance()`, `__call`, `__callStatic`, `forwardCallTo()`,
 * `newQuery()`, `newModelQuery()`, `newEloquentBuilder()`, `newQueryWithoutScopes()`, or a builder overriding
 * `make()`/`newModelInstance()`), where `new` would run different code than `make()`. `self::make()`,
 * `static::make()` and `parent::make()` are neither reported nor fixed: late static binding and object-context
 * `__call` dispatch make `new` non-equivalent. Below a PHP 8.4 target, `new Post()` cannot be dereferenced, so a
 * chained call gets parentheses.
 *
 * @see https://github.com/larastan/larastan/blob/2.x/src/Rules/NoModelMakeRule.php
 */
// This handler uses AfterExpressionAnalysisInterface which fires per-expression.
// The instanceof StaticCall check (line 35) rejects ~95% of expressions immediately.
// An alternative would be emitting from ModelMethodHandler's __callStatic path, but
// that would couple rule enforcement with type resolution. Kept separate for clarity.
final class ModelMakeHandler implements AfterExpressionAnalysisInterface
{
    /** @inheritDoc */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        if (!$expr instanceof StaticCall) {
            return null;
        }

        // Only handle named method calls (not dynamic ::$method())
        // PHP method names are case-insensitive, so normalize before comparing
        if (!$expr->name instanceof Identifier || \strtolower($expr->name->name) !== 'make') {
            return null;
        }

        // Only handle named class references (not dynamic $class::make())
        if (!$expr->class instanceof Name) {
            return null;
        }

        $source = $event->getStatementsSource();
        $className = $expr->class->getAttribute('resolvedName');
        if (!\is_string($className)) {
            return null;
        }

        if (!self::isModelSubclass($className, $event)) {
            return null;
        }

        // If the class (or a non-Model ancestor) declares its own make() method,
        // the call is not magic indirection — it's a legitimate custom method.
        if (self::hasCustomMakeMethod($className, $event)) {
            return null;
        }

        $shortName = self::shortClassName($className);

        $issue = new ModelMakeDiscouraged(
            "Use new {$shortName}(...) instead of {$shortName}::make(...). "
            . 'The constructor is clearer and avoids magic method indirection.',
            new CodeLocation($source, $expr),
        );

        $codebase = $event->getCodebase();

        if (
            $expr->isFirstClassCallable()
            || !self::hasStockCreationPipeline($className, $codebase)
            || ModelCallGuard::isUnsafeEditSite($source, true)
            || ModelCallGuard::mayRunInObjectContextOf($codebase, $className, $source, $event->getContext())
        ) {
            IssueBuffer::accepts($issue, $source->getSuppressedIssues());

            return null;
        }

        $replacements = PsalterFix::fixOrReport(
            $codebase,
            $source,
            [$issue],
            static fn(): array => self::replacements($expr, $codebase, $source->getFilePath()),
        );

        if ($replacements !== null) {
            $event->setFileReplacements($replacements);
        }

        return null;
    }

    /**
     * `Post::make(...)` -> `new Post(...)`, as two edits that never touch the class name or the arguments:
     * insert `new ` before the class and delete `::make`.
     *
     * @return list<FileManipulation>
     */
    private static function replacements(StaticCall $expr, Codebase $codebase, string $filePath): array
    {
        \assert($expr->class instanceof Name && $expr->name instanceof Identifier);

        $classStart = $expr->class->getStartFilePos();
        $classEnd = $expr->class->getEndFilePos() + 1;
        $callEnd = $expr->getEndFilePos() + 1;

        $wrap = $codebase->analysis_php_version_id < 80400 && self::isDereferenced($codebase, $filePath, $callEnd);

        $edits = [
            new FileManipulation($classStart, $classStart, $wrap ? '(new ' : 'new '),
            new FileManipulation($classEnd, $expr->name->getEndFilePos() + 1, ''),
        ];

        if ($wrap) {
            $edits[] = new FileManipulation($callEnd, $callEnd, ')');
        }

        return $edits;
    }

    /**
     * Whether `->`, `?->`, `::`, `[` or `(` follows the call after any whitespace and comments. The event
     * carries no parent node, so the source is read. An unreadable file or an unterminated comment counts as
     * dereferenced: the parentheses are always valid, so unsure means wrap.
     */
    private static function isDereferenced(Codebase $codebase, string $filePath, int $offset): bool
    {
        try {
            $source = $codebase->file_provider->getContents($filePath);
        } catch (\UnexpectedValueException) {
            return true;
        }

        $length = \strlen($source);

        while ($offset < $length) {
            if (\ctype_space($source[$offset])) {
                ++$offset;
                continue;
            }

            if (\str_starts_with(\substr($source, $offset, 2), '/*')) {
                $end = \strpos($source, '*/', $offset + 2);

                if ($end === false) {
                    return true;
                }

                $offset = $end + 2;
                continue;
            }

            // `#[` opens an attribute, never a comment.
            if (\substr($source, $offset, 2) === '//' || ($source[$offset] === '#' && ($source[$offset + 1] ?? '') !== '[')) {
                // A line comment ends at LF or CR, whichever comes first.
                $end = $offset + \strcspn($source, "\r\n", $offset);

                if ($end >= $length) {
                    return false;
                }

                $offset = $end + 1;
                continue;
            }

            return \in_array($source[$offset], ['[', '('], true)
                || \in_array(\substr($source, $offset, 2), ['->', '::'], true)
                || \substr($source, $offset, 3) === '?->';
        }

        return false;
    }

    /**
     * @psalm-assert-if-true class-string<Model> $className
     */
    private static function isModelSubclass(string $className, AfterExpressionAnalysisEvent $event): bool
    {
        if ($className === Model::class) {
            return true;
        }

        $codebase = $event->getCodebase();

        if (!$codebase->classExists($className)) {
            return false;
        }

        return $codebase->classExtends($className, Model::class);
    }

    /**
     * Checks whether the class (or a non-Model ancestor) declares its own make() method.
     * When a custom make() exists, the call bypasses __callStatic magic entirely.
     *
     * @psalm-mutation-free
     */
    private static function hasCustomMakeMethod(string $className, AfterExpressionAnalysisEvent $event): bool
    {
        $codebase = $event->getCodebase();

        try {
            $classStorage = $codebase->classlike_storage_provider->get(\strtolower($className));
        } catch (\InvalidArgumentException) {
            return false;
        }

        $declaringId = $classStorage->declaring_method_ids['make'] ?? null;

        if ($declaringId === null) {
            return false;
        }

        // If make() is declared on a class other than the base Model,
        // it's a custom method — not magic indirection
        return $declaringId->fq_class_name !== Model::class;
    }

    /**
     * Whether `new $className(...)` is what `make()` runs. `make()` is `__callStatic` -> `__call` -> `newQuery()`
     * -> Builder::make() -> newModelInstance() -> Model::newInstance() -> `new static`, so the rewrite is
     * equivalent only while the constructor and `newInstance()` are Eloquent's own, no model in the chain
     * customizes the magic dispatch or the query/builder factories, and the model's Eloquent builder overrides
     * neither `make()` nor `newModelInstance()`. A model that customizes any of them, even without a constructor of
     * its own, would silently lose that behavior. A `newEloquentBuilder()` override counts even when it is untyped:
     * the plugin cannot resolve its builder class then, so the builder checks below would pass on the stock Builder.
     * Booting counts too ({@see ModelCallGuard::customizesBoot()}): a global scope registered there can add pending
     * attributes that `make()` applies and `new` does not.
     *
     * @param class-string<Model> $className
     */
    private static function hasStockCreationPipeline(string $className, Codebase $codebase): bool
    {
        try {
            $classStorage = $codebase->classlike_storage_provider->get(\strtolower($className));
        } catch (\InvalidArgumentException) {
            return false;
        }

        foreach (['__construct', 'newinstance'] as $method) {
            if (($classStorage->declaring_method_ids[$method] ?? null)?->fq_class_name !== Model::class) {
                return false;
            }
        }

        $dispatchHooks = ['__call', '__callstatic', 'newquery', 'newmodelquery', 'neweloquentbuilder', 'newquerywithoutscopes', 'forwardcallto', 'forwarddecoratedcallto'];

        if (ModelCallGuard::overridesAny($codebase, $className, $dispatchHooks) || ModelCallGuard::customizesBoot($codebase, $className)) {
            return false;
        }

        try {
            $builderStorage = $codebase->classlike_storage_provider->get(
                \strtolower(ModelMethodHandler::getBuilderClassForModel($classStorage->name)),
            );
        } catch (\InvalidArgumentException) {
            return false;
        }

        foreach (['make', 'newmodelinstance'] as $method) {
            if (($builderStorage->declaring_method_ids[$method] ?? null)?->fq_class_name !== Builder::class) {
                return false;
            }
        }

        return true;
    }

    /** @psalm-pure */
    private static function shortClassName(string $fqcn): string
    {
        $pos = \strrpos($fqcn, '\\');

        return $pos !== false ? \substr($fqcn, $pos + 1) : $fqcn;
    }
}
