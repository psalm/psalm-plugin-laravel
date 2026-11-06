<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\CodeLocation\Raw;
use Psalm\Config;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Issues\UnusedViewDeclaration;
use Psalm\Plugin\EventHandler\BeforeFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;

/**
 * Reports, at the declaring line of a template, a raw `@var` that nothing in the template's include
 * chain reads. {@see ReadSetResolver::staleDeclarations()} decides, and
 * {@see \Psalm\LaravelPlugin\Handlers\Views\ViewContractHandler} skips MissingViewData for the same
 * declarations, so the two never disagree about whether a call site owes the variable.
 *
 * Fires on the template's shadow, the one file analysis visits per template, and emits against the
 * template path, like {@see RawDeclarationHandler}. A `@psalm-suppress UnusedViewDeclaration` in the
 * declaring docblock, or a Blade `{{-- @psalm-suppress --}}` above the block it sits in, silences it.
 *
 * @internal
 */
final class UnusedDeclarationHandler implements BeforeFileAnalysisInterface
{
    #[\Override]
    public static function beforeAnalyzeFile(BeforeFileAnalysisEvent $event): void
    {
        $entry = ShadowRegistry::entryFor($event->getStatementsSource()->getFilePath());

        if (!$entry instanceof ShadowEntry) {
            return;
        }

        // A published override owns several names, all of one template and one chain.
        $viewName = ViewReferenceRegistry::namesFor($entry->templatePath)[0] ?? null;
        $contract = $viewName === null ? null : ContractRegistry::contractFor($viewName);

        if ($viewName === null || !$contract instanceof ViewDataContract) {
            return;
        }

        $stale = ReadSetResolver::staleDeclarations($viewName);

        if ($stale === []) {
            return;
        }

        $source = ShadowRegistry::templateSource($entry->templatePath);

        if ($source === null) {
            return;
        }

        $name = Config::getInstance()->shortenFileName($entry->templatePath);

        foreach ($stale as $variable) {
            $line = $contract->vars[$variable]->declarationLine;
            $location = TemplateLocation::atLine($entry->templatePath, $name, $source, $line);

            if (!$location instanceof Raw) {
                continue;
            }

            [$native, $blockLine] = ContractParser::suppressionContext($source, $line);

            IssueBuffer::accepts(
                new UnusedViewDeclaration(
                    "\${$variable} is declared with @var but nothing in this template or the templates it includes reads it. Remove the declaration.",
                    $location,
                ),
                [...$native, ...($entry->suppressions[$line] ?? []), ...($entry->suppressions[$blockLine] ?? [])],
            );
        }
    }
}
