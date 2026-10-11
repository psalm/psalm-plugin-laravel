<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\CodeLocation\Raw;
use Psalm\Config;
use Psalm\Issue\InvalidDocblock;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\BeforeFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;

/**
 * Reports, at the declaring line of a template, a raw `@var T $x = null` whose type excludes null.
 * Psalm reads the docblock as `T` while the call-site check widens it to `T|null`, so the template
 * body and its callers would disagree.
 *
 * Fires on the template's shadow, the one file analysis visits per template, and emits against the
 * template path. A `@psalm-suppress InvalidDocblock` in the declaring docblock, or a Blade
 * `{{-- @psalm-suppress --}}` above the block it sits in, silences it.
 *
 * @internal
 */
final class RawDeclarationHandler implements BeforeFileAnalysisInterface
{
    #[\Override]
    public static function beforeAnalyzeFile(BeforeFileAnalysisEvent $event): void
    {
        $entry = ShadowRegistry::entryFor($event->getStatementsSource()->getFilePath());

        if (!$entry instanceof ShadowEntry) {
            return;
        }

        $problems = ContractRegistry::problemsFor($entry->templatePath);

        if ($problems === []) {
            return;
        }

        $source = ShadowRegistry::templateSource($entry->templatePath);

        if ($source === null) {
            return;
        }

        $name = Config::getInstance()->shortenFileName($entry->templatePath);

        foreach ($problems as [$line, $message]) {
            $location = TemplateLocation::atLine($entry->templatePath, $name, $source, $line);

            if ($location instanceof Raw) {
                [$native, $blockLine] = ContractParser::suppressionContext($source, $line);

                IssueBuffer::accepts(
                    new InvalidDocblock($message, $location),
                    [...$native, ...($entry->suppressions[$line] ?? []), ...($entry->suppressions[$blockLine] ?? [])],
                );
            }
        }
    }
}
