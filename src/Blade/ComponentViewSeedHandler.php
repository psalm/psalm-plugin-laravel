<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Node\Stmt\Nop;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\AfterStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\AfterStatementAnalysisEvent;

/**
 * Types a Blade class component's own view from its class (#1804): builds
 * {@see ComponentViewRegistry} once the codebase is populated, then seeds the shadow's scope on the
 * prelude's sentinel: the `Stmt\Nop` its closing tag parses to ({@see PreludeBuilder::build()}).
 *
 * The sentinel is where it has to happen: Psalm applies a statement's `@var` docblocks before
 * analyzing it, so a seed written any earlier would be overwritten by the prelude's own
 * `@var mixed` net, and one written on a later statement would miss the template's first reads.
 * Seeded names are never ones the template declares itself (the registry leaves them out), so a
 * template's contract still wins.
 *
 * Types only: a seeded value carries no taint data-flow node, so taint from a component's
 * properties does not reach the template.
 *
 * @internal
 */
final class ComponentViewSeedHandler implements AfterCodebasePopulatedInterface, AfterStatementAnalysisInterface
{
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        ComponentViewRegistry::build($event->getCodebase());
    }

    #[\Override]
    public static function afterStatementAnalysis(AfterStatementAnalysisEvent $event): ?bool
    {
        $stmt = $event->getStmt();

        if (!$stmt instanceof Nop) {
            return null;
        }

        $shadowPath = $event->getStatementsSource()->getFilePath();
        $seed = ComponentViewRegistry::seedFor($shadowPath);

        // The prelude is the only code on a line the line map sends to template line 0.
        if ($seed === null || (ShadowRegistry::entryFor($shadowPath)?->lineMap[$stmt->getStartLine()] ?? null) !== 0) {
            return null;
        }

        $context = $event->getContext();

        foreach ($seed as $name => $type) {
            $context->vars_in_scope['$' . $name] = $type;
            $context->vars_possibly_in_scope['$' . $name] = true;
        }

        return null;
    }
}
