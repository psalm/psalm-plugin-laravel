<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Type\TypeExpander;
use Psalm\Issue\InvalidArgument;
use Psalm\Issue\TooFewArguments;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\Plugin\EventHandler\AfterStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterStatementAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type\Atomic;
use Psalm\Type\Union;

/**
 * Checks a class component tag's arguments against the component's constructor (#1936).
 *
 * A `<x-alert :count="..." />` tag compiles to one statement,
 * `$component = Alert::resolve([...] + (isset($attributes) && $attributes instanceof ComponentAttributeBag ? $attributes->all() : []));`
 * (`CompilesComponents::compileClassComponentOpening()`), whose literal array the tag compiler has
 * already narrowed to the constructor's parameters, keyed by their camelCased names
 * (`ComponentTagCompiler::partitionDataAndAttributes()`). `Component::resolve()` passes those
 * values by name, through `new static(...)` or the container, both in PHP's weak mode: a numeric
 * static attribute (`count="3"`) reaches an `int` parameter fine, a non-numeric one is a TypeError.
 *
 * Reported as Psalm's own `InvalidArgument` and `TooFewArguments`. Only definite mismatches: a
 * value that may fit, after PHP's weak-mode coercions, is left alone.
 *
 * @internal
 */
final class ComponentArgumentHandler implements AfterStatementAnalysisInterface
{
    #[\Override]
    public static function afterStatementAnalysis(AfterStatementAnalysisEvent $event): ?bool
    {
        $call = self::resolveCall($event->getStmt());
        $data = $call instanceof Expr\StaticCall ? self::compiledData($call) : null;

        if (!$call instanceof Expr\StaticCall || !$data instanceof Expr\Array_) {
            return null;
        }

        $source = $event->getStatementsSource();
        $shadowPath = $source->getFilePath();
        $entry = ShadowRegistry::entryFor($shadowPath);

        if (!$entry instanceof ShadowEntry) {
            return null;
        }

        $class = self::componentClass($call, $source);
        $values = $class === null ? null : self::literalValues($data);

        if ($class === null || $values === null) {
            return null;
        }

        $codebase = $source->getCodebase();
        $constructor = self::constructor($codebase, $class);

        if ($constructor === null) {
            return null;
        }

        [$declaring, $params] = $constructor;
        $methodId = $class . '::__construct';
        $suppressed = $source->getSuppressedIssues();

        foreach ($params as $param) {
            $value = $values[$param->name] ?? null;

            if ($value instanceof Expr) {
                self::checkType($codebase, $source, $param, $value, $declaring, $class, $methodId, $suppressed);
            } elseif (self::isRequired($param) && self::noAttributesInScope($shadowPath, $entry)) {
                IssueBuffer::accepts(
                    new TooFewArguments(
                        "Too few arguments for {$methodId} - expecting \${$param->name} to be passed",
                        new CodeLocation($source, $call),
                        $methodId,
                    ),
                    $suppressed,
                );
            }
        }

        return null;
    }

    /** The `X::resolve(...)` of the compiled `$component = X::resolve(...);` statement. */
    private static function resolveCall(Stmt $stmt): ?Expr\StaticCall
    {
        $assign = $stmt instanceof Stmt\Expression ? $stmt->expr : null;

        if (!$assign instanceof Expr\Assign || !self::isVariable($assign->var, 'component')) {
            return null;
        }

        $call = $assign->expr;

        return $call instanceof Expr\StaticCall
            && $call->name instanceof Identifier
            && $call->name->toLowerString() === 'resolve'
            && \count($call->args) === 1
                ? $call
                : null;
    }

    /**
     * The literal array of `[...] + (isset($attributes) && $attributes instanceof ... ? $attributes->all() : [])`.
     * Anything else is not the compiler's.
     */
    private static function compiledData(Expr\StaticCall $call): ?Expr\Array_
    {
        $arg = $call->getArgs()[0] ?? null;
        $sum = $arg?->value;

        if ($arg === null || $arg->unpack || $arg->name !== null || !$sum instanceof Expr\BinaryOp\Plus) {
            return null;
        }

        $tail = $sum->right;

        $compiled = $sum->left instanceof Expr\Array_
            && $tail instanceof Expr\Ternary
            && $tail->cond instanceof Expr\BinaryOp\BooleanAnd
            && $tail->cond->left instanceof Expr\Isset_
            && $tail->cond->right instanceof Expr\Instanceof_
            && self::isVariable($tail->cond->right->expr, 'attributes')
            && $tail->if instanceof Expr\MethodCall
            && self::isVariable($tail->if->var, 'attributes')
            && $tail->else instanceof Expr\Array_
            && $tail->else->items === [];

        return $compiled ? $sum->left : null;
    }

    /** @psalm-mutation-free */
    private static function isVariable(Expr $expr, string $name): bool
    {
        return $expr instanceof Expr\Variable && $expr->name === $name;
    }

    /**
     * The receiver, `Alert::resolve(` or `Alert::class::resolve(` (`@component(Alert::class, ...)`),
     * when it is a class component whose data reaches its constructor through `Component::resolve()`.
     */
    private static function componentClass(Expr\StaticCall $call, StatementsSource $source): ?string
    {
        $receiver = $call->class;

        if ($receiver instanceof Expr\ClassConstFetch
            && $receiver->name instanceof Identifier
            && $receiver->name->toLowerString() === 'class'
        ) {
            $receiver = $receiver->class;
        }

        if (!$receiver instanceof Name || $receiver->isSpecialClassName()) {
            return null;
        }

        $codebase = $source->getCodebase();
        $class = ClassLineage::canonicalName($codebase, ClassLikeAnalyzer::getFQCLNFromNameObject($receiver, $source->getAliases()));

        try {
            $resolve = $codebase->methods->getDeclaringMethodId(new MethodIdentifier($class, 'resolve'));
        } catch (\InvalidArgumentException) {
            return null;
        }

        // Inheriting `Component::resolve()` is what makes it a component; an overridden one decides
        // for itself what reaches the constructor.
        return $resolve instanceof MethodIdentifier && \strtolower($resolve->fq_class_name) === 'illuminate\view\component'
            ? $class
            : null;
    }

    /**
     * @return array<string, Expr>|null key => value, null when an item is not a literal string key
     *
     * @psalm-mutation-free
     */
    private static function literalValues(Expr\Array_ $data): ?array
    {
        $values = [];

        foreach ($data->items as $item) {
            if ($item === null || $item->unpack || $item->byRef || !$item->key instanceof String_) {
                return null;
            }

            $values[$item->key->value] = $item->value;
        }

        return $values;
    }

    /**
     * The declaring class (`self` in a parameter type) and the constructor's parameters.
     *
     * @return array{string, list<FunctionLikeParameter>}|null
     *
     * @psalm-mutation-free
     */
    private static function constructor(Codebase $codebase, string $class): ?array
    {
        try {
            $declaring = $codebase->methods->getDeclaringMethodId(new MethodIdentifier($class, '__construct'));

            return $declaring instanceof MethodIdentifier
                ? [$declaring->fq_class_name, $codebase->methods->getStorage($declaring)->params]
                : null;
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return null;
        }
    }

    /**
     * @param array<array-key, string> $suppressed
     */
    private static function checkType(
        Codebase $codebase,
        StatementsSource $source,
        FunctionLikeParameter $param,
        Expr $value,
        string $declaring,
        string $class,
        string $methodId,
        array $suppressed,
    ): void {
        $passed = $source->getNodeTypeProvider()->getType($value);

        // A variadic parameter collects the value as one element under its name, not as itself.
        if (!$param->type instanceof Union || $param->is_variadic || !$passed instanceof Union
            || $passed->hasMixed() || $passed->isNever()
        ) {
            return;
        }

        $expected = TypeExpander::expandUnion(
            $codebase,
            $param->type,
            $declaring,
            $class,
            ClassLineage::storage($codebase, $declaring)?->parent_class,
        );

        if ($expected->hasMixed() || $expected->hasTemplate()
            || !self::classesKnown($codebase, $expected) || !self::classesKnown($codebase, $passed)
        ) {
            return;
        }

        foreach ($passed->getAtomicTypes() as $atomic) {
            foreach ([$atomic, ...self::weakCoercions($codebase, $atomic)] as $candidate) {
                if ($codebase->canTypeBeContainedByType(new Union([$candidate]), $expected)) {
                    return;
                }
            }
        }

        IssueBuffer::accepts(
            new InvalidArgument(
                "Argument \${$param->name} of {$methodId} expects {$expected->getId()}, but {$passed->getId()} provided",
                new CodeLocation($source, $value),
                $methodId,
            ),
            $suppressed,
        );
    }

    /**
     * What PHP's weak mode may turn a value into for a scalar parameter. A string that may be numeric
     * may become a number; a non-numeric literal only a bool.
     *
     * @return list<Atomic>
     */
    private static function weakCoercions(Codebase $codebase, Atomic $atomic): array
    {
        return match (true) {
            $atomic instanceof Atomic\TLiteralString => \is_numeric($atomic->value)
                ? [new Atomic\TInt(), new Atomic\TFloat(), new Atomic\TBool()]
                : [new Atomic\TBool()],
            $atomic instanceof Atomic\TString => [new Atomic\TInt(), new Atomic\TFloat(), new Atomic\TBool()],
            $atomic instanceof Atomic\TInt => [new Atomic\TFloat(), new Atomic\TString(), new Atomic\TBool()],
            $atomic instanceof Atomic\TFloat => [new Atomic\TInt(), new Atomic\TString(), new Atomic\TBool()],
            $atomic instanceof Atomic\TBool => [new Atomic\TInt(), new Atomic\TFloat(), new Atomic\TString()],
            $atomic instanceof Atomic\TNamedObject && $codebase->methodExists($atomic->value . '::__toString', is_used: false) => [new Atomic\TString()],
            default => [],
        };
    }

    /** A class nothing defines is reported where it is written; comparing against it proves nothing. */
    private static function classesKnown(Codebase $codebase, Union $type): bool
    {
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof Atomic\TNamedObject && !$codebase->classOrInterfaceOrEnumExists($atomic->value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * What `Container::resolveDependencies()` cannot fill: no default, not variadic, no contextual
     * attribute, and a native type that is absent or neither nullable, `mixed`, nor a class
     * (which the container builds).
     *
     * @psalm-mutation-free
     */
    private static function isRequired(FunctionLikeParameter $param): bool
    {
        if ($param->is_optional || $param->is_variadic || $param->attributes !== []) {
            return false;
        }

        $native = $param->signature_type;

        if (!$native instanceof Union) {
            return true;
        }

        if ($native->isNullable() || $native->hasMixed()) {
            return false;
        }

        foreach ($native->getAtomicTypes() as $atomic) {
            if ($atomic instanceof Atomic\TNamedObject) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the compiled `+ $attributes->all()` provably adds nothing. It adds the enclosing
     * component's attributes wherever `$attributes` is set, which a component view always has, so
     * a missing parameter is only judged in a template that is no component view.
     */
    private static function noAttributesInScope(string $shadowPath, ShadowEntry $entry): bool
    {
        $templateSource = ShadowRegistry::templateSource($entry->templatePath);

        return $templateSource !== null
            && !PreludeBuilder::isComponentView($templateSource)
            && ComponentViewRegistry::seedFor($shadowPath) === null;
    }
}
