<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Nop;
use Psalm\CodeLocation;
use Psalm\LaravelPlugin\Handlers\Views\ViewCallChain;
use Psalm\LaravelPlugin\Handlers\Views\ViewNameSignatures;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterStatementAnalysisEvent;
use Psalm\Type\Union;

/**
 * Carries taint from the data PHP code passes to `view()` into the template that receives it (#2031).
 *
 * A prelude variable is a docblock-typed script global with no data-flow node, so request input handed
 * to a template never reached its `{!! $q !!}`. Psalm 7's keyed taint stores bridge that: the PHP side
 * WRITES each tainted value under the view's name and the variable's name, and the template side READS
 * the same key at the prelude's sentinel. Store nodes carry no location, so a write recorded while one
 * worker analyses a controller and a read recorded while another analyses the shadow are the same node
 * once the parent merges the graphs.
 *
 * This class owns the store naming and every `Codebase` taint call, which keeps Psalm's pre-GA keyed
 * store API in one file.
 *
 * Source side: the `view()` helper, `View::make()`, `Factory::make()`, `response()->view()`, and the
 * literal data array or `with()` entries they pass. Each link of a chain writes only its own
 * arguments, which is what lets a nested `response(view(...))` work. Skipped: a dynamic view name,
 * `View::share()`, composers, `Route::view()`, Livewire, mail, and calls inside compiled templates
 * (`@include` data is its own slice).
 *
 * Sink side: runs on the same sentinel as {@see ComponentViewSeedHandler}, and MUST be registered
 * after it, because the seed replaces a variable's whole type and would drop the nodes attached here.
 *
 * @internal
 */
final class ViewTaintBridge implements AfterExpressionAnalysisInterface, AfterStatementAnalysisInterface
{
    /** `with` and the binders' own names: nothing else can start a bridged call. */
    private const CALL_NAMES = ['view' => true, 'make' => true, 'with' => true];

    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $call = self::bindingCall($event->getExpr());

        if (!$call instanceof Expr\CallLike) {
            return null;
        }

        $source = $event->getStatementsSource();
        $codebase = $event->getCodebase();

        // A template's own `@include` is analysed as a Factory::make() call too; that flow is not
        // part of this bridge, so a shadow never writes.
        if (!$codebase->tracksTaintFlow($source) || ShadowRegistry::entryFor($source->getFilePath()) instanceof ShadowEntry) {
            return null;
        }

        $chain = ViewCallChain::from($call, $source);

        if (!$chain instanceof ViewCallChain
            || (!\in_array($chain->binder, [null, ViewNameSignatures::ROLE_VIEW_FACTORY, ViewNameSignatures::ROLE_RESPONSE_FACTORY], true))
        ) {
            return null;
        }

        foreach (ViewCallChain::ownEntries($call) as [$key, $value]) {
            $type = $source->getNodeTypeProvider()->getType($value);

            if (!$type instanceof Union || $type->parent_nodes === []) {
                continue;
            }

            $node = $codebase->getTaintNode($source, "\${$key} passed to view {$chain->viewName}", new CodeLocation($source, $value));
            $codebase->addTaintStoreWrite($source, self::store($chain->viewName), [[true, $key]], $node, '=', $type);
        }

        return null;
    }

    #[\Override]
    public static function afterStatementAnalysis(AfterStatementAnalysisEvent $event): ?bool
    {
        $stmt = $event->getStmt();

        if (!$stmt instanceof Nop) {
            return null;
        }

        $source = $event->getStatementsSource();
        $entry = ShadowRegistry::entryFor($source->getFilePath());
        $codebase = $event->getCodebase();

        // The prelude is the only code on a line the line map sends to template line 0.
        if (!$entry instanceof ShadowEntry || ($entry->lineMap[$stmt->getStartLine()] ?? null) !== 0 || !$codebase->tracksTaintFlow($source)) {
            return null;
        }

        $views = ViewReferenceRegistry::namesFor($entry->templatePath);

        if ($views === []) {
            return null;
        }

        $context = $event->getContext();
        $location = new CodeLocation($source, $stmt);

        foreach ($context->vars_in_scope as $variable => $type) {
            $name = \substr($variable, 1);

            // Blade supplies these itself (`$errors`, `$slot`, ...), and a `__` name is compiler plumbing.
            if (\str_starts_with($name, '__') || isset(PreludeBuilder::BLADE_OWNED_NAMES[$name])) {
                continue;
            }

            $reads = [];

            foreach ($views as $view) {
                $node = $codebase->getTaintNode($source, "\${$name} (view data of {$view})", $location);
                $codebase->addTaintStoreRead($source, self::store($view), [[true, $name]], $node, '=');
                $reads[$node->id] = $node;
            }

            $context->vars_in_scope[$variable] = $type->addParentNodes($reads);
        }

        return null;
    }

    /**
     * One store per canonical view name; the keys are variable names.
     *
     * @psalm-pure
     */
    private static function store(string $viewName): string
    {
        return 'Illuminate\View\View::$data@' . $viewName;
    }

    /** The cheapest gate: a bridged call is named `view`, `make` or `with`, whatever it is called on. */
    private static function bindingCall(Expr $expr): ?Expr\CallLike
    {
        $name = match (true) {
            $expr instanceof Expr\FuncCall => $expr->name instanceof Name ? $expr->name : null,
            $expr instanceof Expr\MethodCall, $expr instanceof Expr\NullsafeMethodCall, $expr instanceof Expr\StaticCall => $expr->name instanceof Identifier ? $expr->name : null,
            default => null,
        };

        return $name !== null && isset(self::CALL_NAMES[$name->toLowerString()]) && $expr instanceof Expr\CallLike ? $expr : null;
    }
}
