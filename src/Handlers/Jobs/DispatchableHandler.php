<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Jobs;

use Illuminate\Foundation\Bus\Dispatchable as BusDispatchable;
use Illuminate\Foundation\Events\Dispatchable as EventsDispatchable;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Internal\Analyzer\Statements\Expression\CallAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Type\TemplateResult;
use Psalm\Issue\TooManyArguments;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;

/**
 * Validates arguments passed to Dispatchable dispatch/broadcast methods against the job or event's
 * __construct() signature.
 *
 * Both Bus\Dispatchable (jobs) and Events\Dispatchable (events) forward their variadic arguments
 * directly to new static(...$arguments). Because dispatch() is declared as dispatch(mixed ...$arguments),
 * Psalm cannot detect argument mismatches without this handler re-checking the forwarded arguments
 * against the actual constructor.
 *
 * Covered methods:
 * - Bus\Dispatchable:   dispatch(), dispatchIf(), dispatchUnless(), dispatchSync(), dispatchAfterResponse()
 * - Events\Dispatchable: dispatch(), dispatchIf(), dispatchUnless(), broadcast()
 *
 * For dispatchIf/dispatchUnless the first argument is the $boolean condition —
 * the remaining arguments are forwarded to the constructor.
 */
final class DispatchableHandler implements AfterExpressionAnalysisInterface
{
    /**
     * Maps lowercase method names to whether the first argument is a condition (and should be skipped).
     * Covers both Bus\Dispatchable and Events\Dispatchable methods.
     *
     * @var array<lowercase-string, bool>
     */
    private const DISPATCH_METHODS = [
        'dispatch'              => false,  // all args → constructor
        'dispatchsync'          => false,  // Bus\Dispatchable only
        'dispatchafterresponse' => false,  // Bus\Dispatchable only
        'dispatchif'            => true,   // skip first $boolean arg
        'dispatchunless'        => true,   // skip first $boolean arg
        'broadcast'             => false,  // Events\Dispatchable only, all args → constructor
    ];

    /**
     * Cache: "{fqcn}#{methodName}" → bool (is this method from a Dispatchable trait?).
     * The declaring_method_ids are set during scanning and are stable for the worker's lifetime.
     *
     * @var array<string, bool>
     */
    private static array $dispatchableCache = [];

    /** @inheritDoc */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        if (!$expr instanceof StaticCall) {
            return null;
        }

        // Only handle named method calls (not dynamic ::$method())
        if (!$expr->name instanceof Identifier) {
            return null;
        }

        $methodName = \strtolower($expr->name->name);

        if (!isset(self::DISPATCH_METHODS[$methodName])) {
            return null;
        }

        // Only handle named class references (not dynamic $class::dispatch())
        if (!$expr->class instanceof Name) {
            return null;
        }

        $className = $expr->class->getAttribute('resolvedName');
        if (!\is_string($className)) {
            return null;
        }

        $codebase = $event->getCodebase();

        if (!$codebase->classExists($className)) {
            return null;
        }

        // Only handle calls where the method comes from one of the Dispatchable traits.
        // If the class overrides dispatch() with its own signature, skip —
        // Psalm already validates that call correctly.
        if (!self::isDispatchableMethod($className, $methodName, $codebase)) {
            return null;
        }

        $source = $event->getStatementsSource();
        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        $isConditionFirst = self::DISPATCH_METHODS[$methodName];

        // For dispatchIf/dispatchUnless the first arg is the $boolean condition.
        // Strip it before comparing against the constructor signature.
        // array_values(array_slice()) re-indexes and produces list<Arg> for both branches.
        $constructorArgs = \array_values(\array_slice($expr->getArgs(), $isConditionFirst ? 1 : 0));

        $constructorId = new MethodIdentifier($className, '__construct');

        if (!$codebase->methods->methodExists($constructorId)) {
            // No __construct: zero arguments expected.
            if ($constructorArgs !== []) {
                IssueBuffer::maybeAdd(
                    new TooManyArguments(
                        'Class ' . $className . ' has no constructor, but arguments were passed to '
                            . self::shortClassName($className) . '::' . $expr->name->name . '()',
                        new CodeLocation($source, $expr),
                        $className . '::__construct',
                    ),
                    $source->getSuppressedIssues(),
                );
            }

            return null;
        }

        // Delegate full argument validation (count + types) to Psalm's standard
        // argument checker. This emits TooFewArguments, TooManyArguments,
        // InvalidArgument, etc. — exactly the same errors as new ClassName(...).
        CallAnalyzer::checkMethodArgs(
            $constructorId,
            $constructorArgs,
            new TemplateResult([], []),
            $event->getContext(),
            new CodeLocation($source, $expr),
            $source,
        );

        return null;
    }

    /**
     * Returns true when the given method on $className is declared in one of the Dispatchable traits
     * (either Bus\Dispatchable or Events\Dispatchable).
     * If the class overrides the method itself, this returns false and we skip validation
     * (Psalm already handles the actual declared signature).
     *
     * @psalm-external-mutation-free
     */
    private static function isDispatchableMethod(string $className, string $methodName, Codebase $codebase): bool
    {
        $cacheKey = $className . '#' . $methodName;
        if (\array_key_exists($cacheKey, self::$dispatchableCache)) {
            return self::$dispatchableCache[$cacheKey];
        }

        try {
            $classStorage = $codebase->classlike_storage_provider->get(\strtolower($className));
        } catch (\InvalidArgumentException) {
            return self::$dispatchableCache[$cacheKey] = false;
        }

        $declaringId = $classStorage->declaring_method_ids[$methodName] ?? null;

        if ($declaringId === null) {
            return self::$dispatchableCache[$cacheKey] = false;
        }

        $result = $declaringId->fq_class_name === BusDispatchable::class
            || $declaringId->fq_class_name === EventsDispatchable::class;

        return self::$dispatchableCache[$cacheKey] = $result;
    }

    /** @psalm-pure */
    private static function shortClassName(string $fqcn): string
    {
        $pos = \strrpos($fqcn, '\\');

        return $pos !== false ? \substr($fqcn, $pos + 1) : $fqcn;
    }
}
