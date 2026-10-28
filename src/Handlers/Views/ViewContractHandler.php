<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;
use Psalm\CodeLocation;
use Psalm\Exception\TypeParseTreeException;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Blade\BladeBootstrapper;
use Psalm\LaravelPlugin\Blade\ComposerKeyResolver;
use Psalm\LaravelPlugin\Blade\ContractParser;
use Psalm\LaravelPlugin\Blade\ContractRegistry;
use Psalm\LaravelPlugin\Blade\ContractVar;
use Psalm\LaravelPlugin\Blade\PreludeBuilder;
use Psalm\LaravelPlugin\Blade\ReadSetResolver;
use Psalm\LaravelPlugin\Blade\ViewDataContract;
use Psalm\LaravelPlugin\Blade\ViewReferenceRegistry;
use Psalm\LaravelPlugin\Internal\LateStatic;
use Psalm\LaravelPlugin\Issues\InvalidViewData;
use Psalm\LaravelPlugin\Issues\MissingViewData;
use Psalm\LaravelPlugin\Issues\UnusedViewData;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\AfterStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\AfterStatementAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type;

/**
 * Checks a `view()` call site against the contract its template declares: a
 * `@var \App\Models\User $user` docblock or a `@props([...])` entry.
 *
 * Reports a declared variable the call never passes ({@see MissingViewData}) and a passed value
 * that does not satisfy the declared type ({@see InvalidViewData}).
 *
 * Hooked on statement analysis rather than expression analysis because expressions are visited
 * post-order: the inner `view('greeting')` of a `view('greeting')->with('name', $n)` chain would be
 * checked before the `with()` that supplies the data, and would report every declared variable as
 * missing. A statement is visited once, and {@see ViewCallChain} walks it outermost-first, so the
 * whole chain is in hand before anything is reported.
 *
 * Also reports a data key the template never reads ({@see UnusedViewData}): the same walk and the
 * same resolved chain, asked from the other side.
 *
 * Inside a template, the `$__env->make()` call an `@include` compiles to is checked as the
 * `Factory::make()` call site it is, and so are the `renderWhen()`, `renderUnless()` and `first()`
 * calls of `@includeWhen`, `@includeUnless` and `@includeFirst`. Each passes the including scope as
 * `$mergeData`, which leaves the key set open, so only its explicit keys are type-checked;
 * `@includeIsolated` passes no scope and is checked in full.
 *
 * Every check is conservative in the same direction: anything that cannot be proven declines.
 * A missing variable needs the supplied key set to be provably closed, so an open data array
 * (a spread, a dynamic key, `$mergeData`) silences it while leaving the type check on the keys that
 * ARE known.
 */
final class ViewContractHandler implements AfterCodebasePopulatedInterface, AfterStatementAnalysisInterface
{
    /** @var array<string, Type\Union|null> declared type string => parsed union, null when unparseable */
    private static array $declaredTypes = [];

    /** @var array<string, array<string, true>|null> view name => include-chain read set, null when unprovable */
    private static array $readSets = [];

    public static function reset(): void
    {
        self::$declaredTypes = [];
        self::$readSets = [];
    }

    /** Resolved here, before Psalm forks its workers, so each one inherits the result. */
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        ComposerKeyResolver::resolve($event->getCodebase());
    }

    #[\Override]
    public static function afterStatementAnalysis(AfterStatementAnalysisEvent $event): ?bool
    {
        $stmt = $event->getStmt();
        $include = $stmt instanceof Stmt\Echo_ ? self::compiledInclude($stmt) : null;

        // The two statement shapes a rendering expression is the whole of, plus a compiled include.
        // An expression nested deeper (an argument, an array element) is left alone on purpose: the
        // check costs a walk per statement, and reaching those shapes would mean re-walking every
        // sub-expression.
        $expr = match (true) {
            $include instanceof Expr\MethodCall => $include,
            $stmt instanceof Stmt\Expression, $stmt instanceof Stmt\Return_ => $stmt->expr,
            default => null,
        };

        if (!$expr instanceof Expr) {
            return null;
        }

        $source = $event->getStatementsSource();
        $chain = $include instanceof Expr\MethodCall ? ViewCallChain::fromInclude($include, $source) : ViewCallChain::from($expr, $source);

        if (!$chain instanceof \Psalm\LaravelPlugin\Handlers\Views\ViewCallChain) {
            return null;
        }

        $contract = ContractRegistry::contractFor($chain->viewName);

        if (!$contract instanceof \Psalm\LaravelPlugin\Blade\ViewDataContract) {
            return null;
        }

        self::check($chain, $contract, new CodeLocation($source, $expr), $source, $include instanceof Expr\MethodCall);

        return null;
    }

    /**
     * The Factory call of a statement Blade compiles an include directive to:
     * `echo $__env->make(...)->render();` (`@include`, `@includeIf`, `@extends`, `@includeIsolated`),
     * `echo $__env->first(...)->render();` (`@includeFirst`), and
     * `echo $__env->renderWhen(...);` / `renderUnless(...)` (`@includeWhen`, `@includeUnless`).
     * {@see ViewCallChain::fromInclude()} reads its arguments.
     */
    public static function compiledInclude(Stmt\Echo_ $stmt): ?Expr\MethodCall
    {
        $echoed = \count($stmt->exprs) === 1 ? $stmt->exprs[0] : null;

        if (!$echoed instanceof Expr\MethodCall) {
            return null;
        }

        $call = self::isNamed($echoed, 'render') ? $echoed->var : $echoed;
        $names = $call === $echoed ? ['renderwhen', 'renderunless'] : ['make', 'first'];

        return $call instanceof Expr\MethodCall
            && $call->name instanceof Identifier
            && \in_array($call->name->toLowerString(), $names, true)
            && $call->var instanceof Expr\Variable
            && $call->var->name === '__env'
                ? $call
                : null;
    }

    private static function isNamed(Expr\MethodCall $call, string $name): bool
    {
        return $call->name instanceof Identifier && $call->name->toLowerString() === $name;
    }

    private static function check(
        ViewCallChain $chain,
        ViewDataContract $contract,
        CodeLocation $codeLocation,
        StatementsSource $source,
        bool $include,
    ): void {
        $suppressedIssues = $source->getSuppressedIssues();

        // A composer (at render time) or a creator (inside `view()`, before a chained `with()`) can
        // overwrite a key the call site passed, so for the keys it supplies neither presence nor type
        // can be judged from the call site. A view whose composer cannot be read is exempt as a whole.
        $composed = ContractRegistry::composedKeys($chain->viewName);

        if ($composed !== null) {
            $supplied = $chain->supplied();

            foreach ($contract->vars as $name => $var) {
                if (isset($composed[$name])) {
                    continue;
                }

                $passed = $supplied[$name] ?? null;

                if ($passed === null) {
                    self::reportMissing($chain, $contract, $var, $codeLocation, $source, $suppressedIssues);

                    continue;
                }

                self::checkType($chain, $var, $passed, $codeLocation, $source, $suppressedIssues);
            }
        }

        // UnusedViewData judges `view()` call sites only.
        if (!$include) {
            self::reportUnusedData($chain, $contract, $codeLocation, $suppressedIssues);
        }
    }

    /**
     * Deliberately not gated on `$chain->complete`: an open key set only means there may be MORE keys
     * than these, and every key in `$chain->data` was still proven passed.
     *
     * @param array<array-key, string> $suppressedIssues
     */
    private static function reportUnusedData(
        ViewCallChain $chain,
        ViewDataContract $contract,
        CodeLocation $codeLocation,
        array $suppressedIssues,
    ): void {
        // An unreadable `@props` array makes the declared set a lower bound, so "not declared, not
        // read" proves nothing about whether the template wants the key.
        if ($contract->propsUnknown) {
            return;
        }

        $reads = self::readsFor($chain->viewName);

        if ($reads === null) {
            return;
        }

        foreach (\array_keys($chain->data) as $name) {
            // Ambient names are stripped from the read set as Blade's own, so the passed side has to
            // re-check them: a call site that supplies `errors` is not wrong about the template.
            if (isset($reads[$name]) || isset($contract->vars[$name]) || isset(PreludeBuilder::BLADE_OWNED_NAMES[$name])) {
                continue;
            }

            IssueBuffer::accepts(
                new UnusedViewData(
                    "View '{$chain->viewName}' never reads \${$name}, but this call passes '{$name}'. "
                    . 'Remove the key, or declare it with @var if the template needs it.',
                    $codeLocation,
                ),
                $suppressedIssues,
            );
        }
    }

    /**
     * @param array<array-key, string> $suppressedIssues
     */
    private static function reportMissing(
        ViewCallChain $chain,
        ViewDataContract $contract,
        ContractVar $var,
        CodeLocation $codeLocation,
        StatementsSource $source,
        array $suppressedIssues,
    ): void {
        // `optional` is a @props entry with a literal default, which Blade fills in itself, or a
        // declaration ending in `= null`.
        // `propsUnknown` means the declared set is a lower bound, so absence proves nothing about
        // what the template needs, and an open data set proves nothing about what was passed.
        if ($var->optional || $contract->propsUnknown || !$chain->complete) {
            return;
        }

        $templatePath = ViewReferenceRegistry::templatePathFor($chain->viewName);
        $declaredAt = $templatePath === null
            ? ''
            : ', ' . BladeBootstrapper::displayPath($templatePath, $source->getCodebase()->config->base_dir) . ":{$var->declarationLine}";

        IssueBuffer::accepts(
            new MissingViewData(
                "View '{$chain->viewName}' requires \${$var->name} ({$var->typeString}{$declaredAt}), but this call does not pass '{$var->name}'. "
                . 'Pass it, or mark it optional with "= null" or a @props default.',
                $codeLocation,
            ),
            $suppressedIssues,
        );
    }

    /**
     * @param array<array-key, string> $suppressedIssues
     */
    private static function checkType(
        ViewCallChain $chain,
        ContractVar $var,
        Type\Union $supplied,
        CodeLocation $codeLocation,
        StatementsSource $source,
        array $suppressedIssues,
    ): void {
        // Every @props entry is mixed, and a mixed value satisfies anything we could compare it to.
        if ($supplied->hasMixed()) {
            return;
        }

        $codebase = $source->getCodebase();

        // A class nothing defines is already reported where it was written (UndefinedDocblockClass).
        foreach ($supplied->getAtomicTypes() as $atomic) {
            if ($atomic instanceof Type\Atomic\TNamedObject && !$codebase->classOrInterfaceOrEnumExists($atomic->value)) {
                return;
            }
        }

        $declared = self::declaredType($var->optional ? ContractParser::withNull($var->typeString) : $var->typeString);

        if (!$declared instanceof Type\Union || $declared->hasMixed()) {
            return;
        }

        // A short class name may be a `use` alias in the template's own PHP block, which the type
        // string cannot carry: comparing against the global class of that name would be a guess.
        foreach (PreludeBuilder::classNamesIn($var->typeString) ?? [] as $className) {
            if (!PreludeBuilder::resolvesWithoutImport($className)) {
                return;
            }
        }

        // Either form may fit. The raw one still counts so a declaration naming the Renderable itself
        // gains no new report.
        if (
            $codebase->isTypeContainedByType($supplied, $declared)
            || $codebase->isTypeContainedByType(ViewClassProperties::renderablesAsString($supplied, $codebase), $declared)
        ) {
            return;
        }

        IssueBuffer::accepts(
            new InvalidViewData(
                "View '{$chain->viewName}' declares \${$var->name} as {$var->typeString}, but this call passes "
                . LateStatic::strip($supplied)->getId() . ". Pass a value of the declared type, or correct the template's declaration.",
                $codeLocation,
            ),
            $suppressedIssues,
        );
    }

    /**
     * Closing a read set re-walks the whole include chain, and one template is rendered from many
     * call sites. ContractRegistry is frozen after the compile pass, so the answer cannot change.
     *
     * @return array<string, true>|null
     */
    private static function readsFor(string $viewName): ?array
    {
        if (\array_key_exists($viewName, self::$readSets)) {
            return self::$readSets[$viewName];
        }

        return self::$readSets[$viewName] = ReadSetResolver::reads($viewName);
    }

    /**
     * A declared type comes from a template comment, so it is user text that was never validated.
     * An unparseable one is cached as null and silently skipped rather than failing the analysis.
     */
    private static function declaredType(string $typeString): ?Type\Union
    {
        if (\array_key_exists($typeString, self::$declaredTypes)) {
            return self::$declaredTypes[$typeString];
        }

        try {
            $parsed = Type::parseString($typeString);
        } catch (TypeParseTreeException) {
            $parsed = null;
        }

        return self::$declaredTypes[$typeString] = $parsed;
    }
}
