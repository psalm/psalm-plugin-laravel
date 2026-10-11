<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Exception\TypeParseTreeException;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Blade\ContractParser;
use Psalm\LaravelPlugin\Blade\ContractRegistry;
use Psalm\LaravelPlugin\Blade\ContractVar;
use Psalm\LaravelPlugin\Blade\PreludeBuilder;
use Psalm\LaravelPlugin\Blade\ReadSetResolver;
use Psalm\LaravelPlugin\Blade\ShadowEntry;
use Psalm\LaravelPlugin\Blade\ShadowRegistry;
use Psalm\LaravelPlugin\Blade\ViewDataContract;
use Psalm\LaravelPlugin\Blade\ViewReferenceCollector;
use Psalm\LaravelPlugin\Issues\InvalidViewVariableType;
use Psalm\LaravelPlugin\Issues\MissingViewVariable;
use Psalm\LaravelPlugin\Issues\UnusedViewData;
use Psalm\Plugin\EventHandler\AfterStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterStatementAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type;

/**
 * Checks a `view()` call site against the contract its template declares: a
 * `@var \App\Models\User $user` docblock or a `@props([...])` entry.
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
 * Inside a Blade shadow it also checks a compiled `@include('view', [...])` against the included
 * template's contract. The partial receives the literal array AND the including template's scope,
 * so a key absent from the array is missing only when the includer provably cannot hand it on.
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

    /** @var array<string, array<string, true>|null> view name => include-chain read set, null when unprovable */
    private static array $readSets = [];

    public static function init(bool $validateViewData, bool $reportUnusedViewData): void
    {
        self::$validateViewData = $validateViewData;
        self::$reportUnusedViewData = $reportUnusedViewData;
    }

    public static function reset(): void
    {
        self::$validateViewData = false;
        self::$reportUnusedViewData = false;
        self::$declaredTypes = [];
        self::$readSets = [];
    }

    #[\Override]
    public static function afterStatementAnalysis(AfterStatementAnalysisEvent $event): ?bool
    {
        if (!self::$validateViewData && !self::$reportUnusedViewData) {
            return null;
        }

        $stmt = $event->getStmt();

        if ($stmt instanceof Stmt\Echo_) {
            if (self::$validateViewData) {
                self::checkInclude($stmt, $event);
            }

            return null;
        }

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

    /**
     * A compiled `@include` in a Blade shadow. Its location is the `make()` call, which the issue
     * remap moves to the directive's template line.
     */
    private static function checkInclude(Stmt\Echo_ $stmt, AfterStatementAnalysisEvent $event): void
    {
        $source = $event->getStatementsSource();
        $entry = ShadowRegistry::entryFor($source->getFilePath());
        $make = $entry instanceof ShadowEntry ? self::compiledInclude($stmt, $source) : null;

        if (!$entry instanceof ShadowEntry || !$make instanceof Expr\MethodCall) {
            return;
        }

        $inherited = self::inheritedNames($entry->templatePath, $event->getContext());
        $chain = ViewCallChain::fromInclude($make, $source, $inherited !== null);
        $contract = $chain instanceof ViewCallChain ? ContractRegistry::contractFor($chain->viewName) : null;

        if (!$chain instanceof ViewCallChain || !$contract instanceof ViewDataContract) {
            return;
        }

        self::check($chain, $contract, new CodeLocation($source, $make), $source, $inherited ?? []);
    }

    /**
     * The `make()` call of the echo Blade compiles `@include('view', [...])` to:
     * `<?php echo $__env->make('view', [...], array_diff_key(get_defined_vars(), …))->render(); ?>`.
     *
     * `@includeIf` compiles to the same echo behind `if ($__env->exists(…))`, so the echo must also
     * open its own PHP block. A suppression docblock the shadow injected after the open tag is
     * skipped. The other include directives compile to other calls or drop the scope argument.
     */
    private static function compiledInclude(Stmt\Echo_ $stmt, StatementsSource $source): ?Expr\MethodCall
    {
        $render = \count($stmt->exprs) === 1 ? $stmt->exprs[0] : null;

        if (!$render instanceof Expr\MethodCall || !$render->name instanceof Identifier || $render->name->toLowerString() !== 'render') {
            return null;
        }

        $make = $render->var;

        if (!$make instanceof Expr\MethodCall
            || !$make->name instanceof Identifier
            || $make->name->toLowerString() !== 'make'
            || !$make->var instanceof Expr\Variable
            || $make->var->name !== '__env'
            || \count($make->args) !== 3
            || !$make->args[1] instanceof Arg
            || !$make->args[1]->value instanceof Expr\Array_
            || !ViewReferenceCollector::passesWholeScope([$make->args[2]])
        ) {
            return null;
        }

        $position = $stmt->getStartFilePos();
        $window = \max(0, $position - 512);
        $before = \rtrim(\substr($source->getCodebase()->getFileContents($source->getFilePath()), $window, $position - $window));

        if (\str_ends_with($before, '*/')) {
            $before = \rtrim(\substr($before, 0, (int) \strrpos($before, '/**')));
        }

        return \str_ends_with($before, '<?php') ? $make : null;
    }

    /**
     * Every name the including template can hand on through the scope `@include` forwards: what it
     * declares, reads (an undeclared read is data it was passed) or binds, what Blade supplies, and
     * whatever Psalm holds in scope at the directive (a class component's data, a local assigned
     * above it). Null when that scope is not provable: no contract was registered for the template,
     * a composer feeds it, it declares nothing and so states no scope at all, its `@props` are
     * unreadable, or its scope is dynamic (`$$name`, `extract()`).
     *
     * @return array<string, true>|null
     */
    private static function inheritedNames(string $templatePath, Context $context): ?array
    {
        $includer = ContractRegistry::templateScopeFor($templatePath);

        if (!$includer instanceof ViewDataContract
            || $includer->vars === []
            || $includer->propsUnknown
            || $includer->readsUnknown
            || !$context->check_variables
        ) {
            return null;
        }

        $names = PreludeBuilder::BLADE_OWNED_NAMES
            + \array_fill_keys(\array_keys($includer->vars), true)
            + \array_fill_keys($includer->readVariables, true)
            + \array_fill_keys($includer->rawDeclaredVariables, true);

        foreach ([...\array_keys($context->vars_in_scope), ...\array_keys($context->vars_possibly_in_scope)] as $varId) {
            $names[\substr($varId, 1)] = true;
        }

        return $names;
    }

    /**
     * @param array<string, true>|null $inheritedNames for an `@include`, the names the includer's
     *        scope can supply; null for a PHP call site, which inherits nothing
     */
    private static function check(
        ViewCallChain $chain,
        ViewDataContract $contract,
        CodeLocation $codeLocation,
        StatementsSource $source,
        ?array $inheritedNames = null,
    ): void {
        $suppressedIssues = $source->getSuppressedIssues();

        // A composer or creator can overwrite any key after the call site passed it, so neither what
        // is missing nor what a value's type is can be judged from the call site alone.
        if (self::$validateViewData && !ContractRegistry::isComposed($chain->viewName)) {
            $supplied = $chain->supplied();

            foreach ($contract->vars as $name => $var) {
                $passed = $supplied[$name] ?? null;

                if ($passed === null) {
                    if (isset($inheritedNames[$name])) {
                        continue;
                    }

                    self::reportMissing($chain, $contract, $var, $codeLocation, $suppressedIssues);

                    continue;
                }

                self::checkType($chain, $var, $passed, $codeLocation, $source, $suppressedIssues);
            }
        }

        // UnusedViewData judges `view()` call sites only; an include's extra keys are out of its scope.
        if (self::$reportUnusedViewData && $inheritedNames === null) {
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
        // `optional` is a @props entry with a literal default, which Blade fills in itself, or a
        // declaration ending in `= null`.
        // `propsUnknown` means the declared set is a lower bound, so absence proves nothing about
        // what the template needs; an open data set proves nothing about what was passed; and a
        // composer or creator registered for the view supplies data no call site shows.
        if ($var->optional || $contract->propsUnknown || !$chain->complete || ContractRegistry::isComposed($chain->viewName)) {
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
