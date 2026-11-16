<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\CodeLocation\Raw;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Issues\BladeCompileError;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;

/**
 * Reports the templates the compiler threw on as `BladeCompileError`, once Psalm has built its list
 * of files passed on the command line.
 *
 * Not at plugin boot: `ProjectAnalyzer::checkPaths()` initializes plugins BEFORE it fills that list,
 * and `Config::reportIssueInFile()` treats an empty list as "no filter", so a failure reported at
 * boot would leak into a `hideAllErrorsExceptPassedFiles` run on a single unrelated file. The
 * templates themselves are made reportable at boot (`ShadowRegistrar::makeTemplatesReportable()`),
 * which scan-time issues need.
 *
 * Registered only when the boot recorded a failure.
 *
 * @internal
 */
final class CompileFailureReporter implements AfterCodebasePopulatedInterface
{
    /** @var array<string, array{0: string, 1: string}> template path => [source, compiler error] */
    private static array $failures = [];

    public static function record(string $templatePath, string $source, string $message): void
    {
        self::$failures[$templatePath] = [$source, $message];
    }

    public static function hasFailures(): bool
    {
        return self::$failures !== [];
    }

    public static function reset(): void
    {
        self::$failures = [];
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $config = $event->getCodebase()->config;

        foreach (self::$failures as $templatePath => [$source, $message]) {
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
