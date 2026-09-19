<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use Psalm\CodeLocation\Raw;
use Psalm\Config;
use Psalm\Internal\Provider\FileStorageProvider;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Blade\PsalmBridge;
use Psalm\LaravelPlugin\Blade\ShadowRegistry;
use Psalm\LaravelPlugin\Blade\TemplateLocation;
use Psalm\LaravelPlugin\Blade\ViewReferenceCollector;
use Psalm\LaravelPlugin\Blade\ViewReferenceRegistry;
use Psalm\LaravelPlugin\Issues\UnusedView;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Progress\Progress;

/**
 * Reports a Blade template ({@see UnusedView}) that BladeBootstrapper discovered but that no
 * statically-provable reference — a `view()`/`View::make()` call site, or an `@include`/`@extends`
 * from another template — ever names.
 *
 * Collection cannot happen during analysis: Psalm 6 forks analysis workers, and plugin statics never
 * return from a fork (`Internal/Codebase/Analyzer.php`). `AfterCodebasePopulated` runs once in the
 * parent, before that fork, with the full project file list already known, so the call-site half of
 * collection (every plain project file's `view()`/`View::make()`) happens here; the template-side
 * half (`@include`/`@extends`) already ran at compile time, in `BladeBootstrapper::compileAll()`.
 *
 * One reference this plugin cannot resolve statically — a dynamic `view($x)` or `@include($x)`
 * anywhere in the project — makes the whole enumerated reference set a lower bound, not a fact, so
 * the check declines for every template in the run rather than risk a false UnusedView.
 *
 * @psalm-api registered only through Psalm's plugin hook API (`registerHooksFromClass`), which is
 *            not a reference Psalm's own dead-code analysis can see.
 * @internal
 */
final class UnusedViewHandler implements AfterCodebasePopulatedInterface
{
    private static ?Progress $output = null;

    public static function init(Progress $output): void
    {
        self::$output = $output;
    }

    public static function reset(): void
    {
        self::$output = null;
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();

        // Psalm 6 runs one mode per invocation and discards every non-taint issue under
        // --taint-analysis, so the whole walk would be paid for nothing.
        if (PsalmBridge::isTaintRun($codebase)) {
            return;
        }

        $config = Config::getInstance();
        $collector = new ViewReferenceCollector();

        foreach (FileStorageProvider::getAll() as $storage) {
            if (!$config->isInProjectDirs($storage->file_path)) {
                // Excludes the compiled shadows themselves: their cache directory sits outside the
                // project tree by construction (docs/blade.md), so this is also what stops the
                // template-side references from being collected a second time here.
                continue;
            }

            [$viewNames, $dynamic] = $collector->collect($codebase->getStatementsForFile($storage->file_path));

            foreach ($viewNames as $viewName) {
                ViewReferenceRegistry::addReference($viewName);
            }

            if ($dynamic) {
                ViewReferenceRegistry::markDynamic();
            }
        }

        if (ViewReferenceRegistry::isDynamic()) {
            self::$output?->warning(
                'Laravel plugin: UnusedView is disabled for this run because a view reference could '
                . 'not be resolved statically (a dynamic view() call or @include).',
            );

            return;
        }

        foreach (ViewReferenceRegistry::unusedTemplates() as $viewName => [$templatePath, $shadowPath]) {
            self::report($viewName, $templatePath, $shadowPath);
        }
    }

    private static function report(string $viewName, string $templatePath, ?string $shadowPath): void
    {
        $source = ShadowRegistry::templateSource($templatePath);

        if ($source === null) {
            return;
        }

        $location = TemplateLocation::atLine($templatePath, Config::getInstance()->shortenFileName($templatePath), $source, 1);

        if (!$location instanceof Raw) {
            return;
        }

        $entry = $shadowPath !== null ? ShadowRegistry::entryFor($shadowPath) : null;

        IssueBuffer::accepts(
            new UnusedView("View '{$viewName}' is never rendered.", $location),
            $entry?->suppressions[1] ?? [],
        );
    }
}
