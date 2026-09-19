<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use Psalm\CodeLocation;
use Psalm\Exception\TypeParseTreeException;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Blade\ContractRegistry;
use Psalm\LaravelPlugin\Blade\ContractVar;
use Psalm\LaravelPlugin\Blade\PreludeBuilder;
use Psalm\LaravelPlugin\Blade\ReadSetResolver;
use Psalm\LaravelPlugin\Blade\ViewDataContract;
use Psalm\LaravelPlugin\Issues\InvalidViewVariableType;
use Psalm\LaravelPlugin\Issues\MissingViewVariable;
use Psalm\LaravelPlugin\Issues\UnusedViewData;
use Psalm\Plugin\EventHandler\AfterStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterStatementAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type;

/**
 * Checks a `view()` call site against the contract its template declares: a
 * `{{-- @var \App\Models\User $user --}}` comment or a `@props([...])` entry.
 *
 * Reports a declared variable the call never passes ({@see MissingViewVariable}) and a passed value
 * that does not satisfy the declared type ({@see InvalidViewVariableType}).
 *
 * Hooked on statement analysis rather than expression analysis because expressions are visited
 * post-order: the inner `view('greeting')` of a `view('greeting')->with('name', $n)` chain would be
 * checked before the `with()` that supplies the data, and would report every declared variable as
 * missing. A statement is visited once, and {@see ViewCallChain} walks it outermost-first, so the
 * whole chain is in hand before anything is reported.
 *
 * Also reports, under its own independent flag, a data key the template never reads
 * ({@see UnusedViewData}) — the same walk and the same resolved chain, asked from the other side.
 *
 * Every check is conservative in the same direction: anything that cannot be proven declines.
 * A missing variable needs the supplied key set to be provably closed, so an open data array
 * (a spread, a dynamic key, `$mergeData`) silences it while leaving the type check on the keys that
 * ARE known.
 */
final class ViewContractHandler implements AfterStatementAnalysisInterface
{
    private static bool $validateViewData = false;

    private static bool $reportUnusedViewData = false;

    /** @var array<string, Type\Union|null> declared type string => parsed union, null when unparseable */
    private static array $declaredTypes = [];

    /** @psalm-external-mutation-free */
    public static function init(bool $validateViewData, bool $reportUnusedViewData): void
    {
        self::$validateViewData = $validateViewData;
        self::$reportUnusedViewData = $reportUnusedViewData;
    }

    /** @psalm-external-mutation-free */
    public static function reset(): void
    {
        self::$validateViewData = false;
        self::$reportUnusedViewData = false;
        self::$declaredTypes = [];
    }

    #[\Override]
    public static function afterStatementAnalysis(AfterStatementAnalysisEvent $event): ?bool
    {
        if (!self::$validateViewData && !self::$reportUnusedViewData) {
            return null;
        }

        $stmt = $event->getStmt();

        // The two statement shapes a rendering expression is the whole of. An expression nested
        // deeper (an argument, an array element) is left alone on purpose: the check costs a walk
        // per statement, and reaching those shapes would mean re-walking every sub-expression.
        $expr = match (true) {
            $stmt instanceof Stmt\Expression, $stmt instanceof Stmt\Return_ => $stmt->expr,
            default => null,
        };

        if (!$expr instanceof Expr) {
            return null;
        }

        $source = $event->getStatementsSource();
        $chain = ViewCallChain::from($expr, $source);

        if (!$chain instanceof \Psalm\LaravelPlugin\Handlers\Views\ViewCallChain) {
            return null;
        }

        $contract = ContractRegistry::contractFor($chain->viewName);

        if (!$contract instanceof \Psalm\LaravelPlugin\Blade\ViewDataContract) {
            return null;
        }

        self::check($chain, $contract, new CodeLocation($source, $expr), $source);

        return null;
    }

    private static function check(
        ViewCallChain $chain,
        ViewDataContract $contract,
        CodeLocation $codeLocation,
        StatementsSource $source,
    ): void {
        $suppressedIssues = $source->getSuppressedIssues();

        if (self::$validateViewData) {
            foreach ($contract->vars as $name => $var) {
                $supplied = $chain->data[$name] ?? null;

                if ($supplied === null) {
                    self::reportMissing($chain, $contract, $var, $codeLocation, $suppressedIssues);

                    continue;
                }

                self::checkType($chain, $var, $supplied, $codeLocation, $source, $suppressedIssues);
            }
        }

        if (self::$reportUnusedViewData) {
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

        $reads = ReadSetResolver::reads($chain->viewName);

        if ($reads === null) {
            return;
        }

        foreach (\array_keys($chain->data) as $name) {
            // Ambient names are stripped from the read set as Blade's own, so the passed side has to
            // re-check them: a call site that supplies `errors` is not wrong about the template.
            if (isset($reads[$name]) || isset($contract->vars[$name]) || isset(PreludeBuilder::AMBIENT_TYPES[$name])) {
                continue;
            }

            IssueBuffer::accepts(
                new UnusedViewData(
                    "View '{$chain->viewName}' never reads \${$name}, but the call site passes '{$name}' to it",
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
        array $suppressedIssues,
    ): void {
        // `optional` is a @props entry with a literal default, which Blade fills in itself.
        // `propsUnknown` means the declared set is a lower bound, so absence proves nothing about
        // what the template needs; and an open data set proves nothing about what was passed.
        if ($var->optional || $contract->propsUnknown || !$chain->complete) {
            return;
        }

        IssueBuffer::accepts(
            new MissingViewVariable(
                "View '{$chain->viewName}' declares \${$var->name} but the call site does not pass '{$var->name}'",
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

        $declared = self::declaredType($var->typeString);

        if (!$declared instanceof \Psalm\Type\Union || $declared->hasMixed()) {
            return;
        }

        if ($source->getCodebase()->isTypeContainedByType($supplied, $declared)) {
            return;
        }

        IssueBuffer::accepts(
            new InvalidViewVariableType(
                "View '{$chain->viewName}' declares \${$var->name} as {$var->typeString}, but "
                . $supplied->getId() . ' is passed for it',
                $codeLocation,
            ),
            $suppressedIssues,
        );
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
