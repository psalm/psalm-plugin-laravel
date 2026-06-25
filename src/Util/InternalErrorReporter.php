<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

use Psalm\LaravelPlugin\Config\PluginConfig;
use Psalm\LaravelPlugin\Util\Diagnostics\BufferedProgress;
use Psalm\Progress\Progress;

/**
 * Surfaces a plugin-initialisation failure as a {@see Progress::warning()} pair —
 * one for the original error, one with a pre-filled issue-tracker URL so users
 * can report it with environment details attached.
 *
 * Sits next to {@see IssueUrlGenerator}: this class is the wrapper that composes
 * URL generation with output and rethrow behaviour.
 *
 * @internal
 */
final class InternalErrorReporter
{
    /** @throws \Throwable when {@see PluginConfig::$failOnInternalError} is on */
    public static function report(\Throwable $throwable, Progress $output, PluginConfig $pluginConfig): void
    {
        // This reporter composes the whole failure output, so it also owns flushing
        // the diagnostics collected before the failure: they appear with, not buried
        // under, the final error report. Keeping the flush here (over Plugin's catch)
        // keeps the ordering unit-testable. After flushing, terminal messages go
        // straight to the real progress; writing them back through the buffer would
        // re-collect them after flush() already cleared it, so they'd never print.
        $real = $output;
        if ($output instanceof BufferedProgress) {
            $output->flush();
            $real = $output->inner();
        }

        $real->warning("Laravel plugin error on initialisation: {$throwable->getMessage()}");

        // Best-effort classification: tells the user whether the failure
        // looks like an app-level config issue, a plugin bug, a Laravel
        // framework problem, or a Testbench fallback issue.
        $hint = InternalErrorClassifier::hint($throwable);
        if ($hint !== null) {
            $real->warning("Laravel plugin: {$hint}");
        }

        $real->warning('Laravel plugin: ' . ApplicationBootReporter::hardFailureNextSteps());

        // URL generation is best-effort — a secondary failure here (e.g. a
        // throwable with a broken __toString(), a corrupt composer installed.php)
        // must never shadow the original init error that the user actually cares
        // about, so we fall back to a plain issue-tracker link. The secondary
        // error is still surfaced as a separate warning so plugin maintainers can
        // spot regressions in the URL generator during self-analysis.
        try {
            $url = IssueUrlGenerator::generate($throwable, $pluginConfig);
        } catch (\Throwable $urlGenerationFailure) {
            $real->warning(
                "Laravel plugin failed to build a detailed report URL: {$urlGenerationFailure->getMessage()}",
            );
            $url = 'https://github.com/psalm/psalm-plugin-laravel/issues';
        }

        $real->warning('Laravel plugin has been disabled for this run, please report about this issue: ' . $url);

        if ($pluginConfig->failOnInternalError) {
            throw $throwable;
        }
    }
}
