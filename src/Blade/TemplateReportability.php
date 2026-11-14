<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\CodeLocation\Raw;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\IssueBuffer;
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
 * @internal
 */
final class TemplateReportability implements AfterCodebasePopulatedInterface
{
    /** @var list<string> */
    private static array $templatePaths = [];

    /** @var array<string, array{0: string, 1: string}> template path => [source, compiler error] */
    private static array $compileFailures = [];

    /**
     * @param list<string> $paths `.blade.php` paths, never shadow paths
     */
    public static function record(array $paths): void
    {
        self::$templatePaths = \array_values(\array_unique([...self::$templatePaths, ...$paths]));
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
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        // Before any report: the issues below are filtered through the project-file list.
        ProjectFileInjector::inject(ProjectAnalyzer::getInstance(), self::$templatePaths);

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
