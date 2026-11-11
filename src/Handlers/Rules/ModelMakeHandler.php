<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\FileManipulation;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Internal\PsalterFix;
use Psalm\LaravelPlugin\Issues\ModelMakeDiscouraged;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\StatementsSource;

/**
 * Flags Model::make() calls and suggests using new Model() instead.
 *
 * Model::make() is forwarded through magic methods (__callStatic -> __call ->
 * forwardCallTo) to Builder::make(), which just creates a new instance via
 * newModelInstance(). Using the constructor directly is clearer and avoids
 * the indirection.
 *
 * Psalter fixes it: `Post::make($a)` becomes `new Post($a)`, `static::make()` becomes `new static()`.
 * The fix leaves the class name and the arguments untouched and only inserts `new ` and removes
 * `::make`. It is withheld (the issue is still reported) for a first-class callable `Post::make(...)`,
 * and for a model whose constructor is not Eloquent's own, where `new` would run different code than
 * `make()`. Below a PHP 8.4 target, `new Post()` cannot be dereferenced, so a chained call gets parentheses.
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
        $className = self::resolveClassName($expr->class, $source);
        if ($className === null) {
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

        // `static::make()` / `self::make()` are written as such, not as the enclosing model's name.
        $written = $expr->class->isSpecialClassName() ? $expr->class->toString() : self::shortClassName($className);

        $issue = new ModelMakeDiscouraged(
            "Use new {$written}(...) instead of {$written}::make(...). "
            . 'The constructor is clearer and avoids magic method indirection.',
            new CodeLocation($source, $expr),
        );

        $codebase = $event->getCodebase();

        if ($expr->isFirstClassCallable() || !self::usesModelConstructor($className, $codebase)) {
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
                $end = \strpos($source, "\n", $offset);

                if ($end === false) {
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
     * `self` / `static` name the enclosing class; `parent` is not resolved. Psalm's name resolver sets
     * `resolvedName` only on real names.
     */
    private static function resolveClassName(Name $class, StatementsSource $source): ?string
    {
        if ($class->isSpecialClassName()) {
            return match ($class->toLowerString()) {
                'self', 'static' => $source->getFQCLN(),
                default => null,
            };
        }

        /** @psalm-var ?string $resolved */
        $resolved = $class->getAttribute('resolvedName');

        return \is_string($resolved) ? $resolved : null;
    }

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
     * Whether `new $className(...)` runs Eloquent's own constructor, which `make()` ends in. A model that
     * declares or inherits another constructor may need arguments or do work `make()` bypasses.
     *
     * @psalm-mutation-free
     */
    private static function usesModelConstructor(string $className, Codebase $codebase): bool
    {
        try {
            $classStorage = $codebase->classlike_storage_provider->get(\strtolower($className));
        } catch (\InvalidArgumentException) {
            return false;
        }

        $declaringId = $classStorage->declaring_method_ids['__construct'] ?? null;

        return $declaringId !== null && $declaringId->fq_class_name === Model::class;
    }

    /** @psalm-pure */
    private static function shortClassName(string $fqcn): string
    {
        $pos = \strrpos($fqcn, '\\');

        return $pos !== false ? \substr($fqcn, $pos + 1) : $fqcn;
    }
}
