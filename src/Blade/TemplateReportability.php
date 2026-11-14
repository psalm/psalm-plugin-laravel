<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\CodeLocation\Raw;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Internal\PluginWarning;
use Psalm\LaravelPlugin\Issues\BladeCompileError;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;

/**
 * Makes `.blade.php` templates reportable, and reports the ones that failed to compile, once Psalm
 * has built its scan lists.
 *
 * A template must sit in `ProjectAnalyzer::$project_files` for an issue to be reportable on it, but
 * Psalm 7.4+ derives its scan and analysis lists from that same property after plugins initialize:
 * a template written at boot would be analyzed as plain PHP. `AfterCodebasePopulated` runs after
 * scanning and before analysis forks its workers, which filter issues through the parent's
 * `Config::reportIssueInFile()`, so the write has to happen here and not in a worker.
 *
 * The boot records; this handler applies. It is registered only when something was recorded.
 *
 * Psalm also reports some diagnostics WHILE scanning a shadow (`DuplicateFunction`), before this
 * hook fires. {@see BladeIssueRemapHandler} re-emits those on the template path and so calls
 * {@see self::apply()} first; the write is idempotent per process. A forked scanner worker applies
 * in its own copy, which dies with it, so the parent still applies here.
 *
 * @internal
 */
final class TemplateReportability implements AfterCodebasePopulatedInterface
{
    /** @var list<string> */
    private static array $templatePaths = [];

    /** @var array<string, array{0: string, 1: string}> template path => [source, compiler error] */
    private static array $compileFailures = [];

    private static bool $applied = false;

    /**
     * @param list<string> $paths `.blade.php` paths, never shadow paths
     */
    public static function record(array $paths): void
    {
        self::$templatePaths = \array_values(\array_unique([...self::$templatePaths, ...$paths]));
        self::$applied = false;
    }

    public static function recordCompileFailure(string $templatePath, string $source, string $message): void
    {
        self::$compileFailures[$templatePath] = [$source, $message];
    }

    public static function hasCompileFailures(): bool
    {
        return self::$compileFailures !== [];
    }

    public static function reset(): void
    {
        self::$templatePaths = [];
        self::$compileFailures = [];
        self::$applied = false;
    }

    /**
     * Writes the recorded templates into Psalm's project-file list, once per process.
     *
     * Safe while Psalm is still scanning: it copied the list before `scanFiles()`, so a later write
     * enqueues nothing.
     *
     * @return bool false when the write failed
     */
    public static function apply(): bool
    {
        if (self::$applied || self::$templatePaths === []) {
            return true;
        }

        self::$applied = ProjectFileInjector::inject(ProjectAnalyzer::getInstance(), self::$templatePaths);

        return self::$applied;
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        // Before any report: the issues below are filtered through the project-file list. The boot
        // probed that the write works, so a failure here means Psalm changed under us; nothing can
        // degrade any more, so say it rather than lose every template issue silently.
        if (!self::apply()) {
            PluginWarning::emit(
                $event->getCodebase()->progress,
                'Laravel plugin: Blade templates could not be made reportable, so no issue found in them is reported.',
            );
        }

        $config = $event->getCodebase()->config;

        foreach (self::$compileFailures as $templatePath => [$source, $message]) {
            $name = $config->shortenFileName($templatePath);
            $location = TemplateLocation::atLine($templatePath, $name, $source, 1)
                ?? new Raw($source, $templatePath, $name, 0, 0);

            // Nothing to suppress from: the template never compiled, so `<issueHandlers>` is the
            // only way to silence it.
            IssueBuffer::accepts(
                new BladeCompileError("Template not analyzed: {$message}", $location),
                [],
            );
        }
    }
}
