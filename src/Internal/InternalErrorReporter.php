<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Psalm\LaravelPlugin\Config\PluginConfig;
use Psalm\Progress\Progress;

/**
 * Surfaces a plugin-initialisation failure as a warning pair —
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
        WarningReporter::emit($output, "Laravel plugin error on initialisation: {$throwable->getMessage()}");

        // Best-effort classification: tells the user whether the failure
        // looks like an app-level config issue, a plugin bug, a Laravel
        // framework problem, or a Testbench fallback issue.
        $hint = InternalErrorClassifier::hint($throwable);
        if ($hint !== null) {
            WarningReporter::emit($output, "Laravel plugin: {$hint}");
        }

        // URL generation is best-effort — a secondary failure here (e.g. a
        // throwable with a broken __toString(), a corrupt composer installed.php)
        // must never shadow the original init error that the user actually cares
        // about, so we fall back to a plain issue-tracker link. The secondary
        // error is still surfaced as a separate warning so plugin maintainers can
        // spot regressions in the URL generator during self-analysis.
        try {
            $url = IssueUrlGenerator::generate($throwable, $pluginConfig);
        } catch (\Throwable $urlGenerationFailure) {
            WarningReporter::emit(
                $output,
                "Laravel plugin failed to build a detailed report URL: {$urlGenerationFailure->getMessage()}",
            );
            $url = 'https://github.com/psalm/psalm-plugin-laravel/issues';
        }

        WarningReporter::emit(
            $output,
            'Laravel plugin has been disabled for this run, please report about this issue: ' . $url,
        );

        if ($pluginConfig->failOnInternalError) {
            throw $throwable;
        }
    }

    /**
     * Surfaces a bootstrap failure that {@see \Psalm\LaravelPlugin\Bootstrap\ApplicationProvider}
     * swallowed to keep the run alive (crash resistance: one bad `config/*.php` must not
     * disable the plugin for the whole run). Unlike {@see report()}, the plugin stays
     * active — handlers still register against the partially-booted app — so the output
     * is a "degraded" warning trio rather than a "disabled" notice.
     *
     * With `failOnInternalError` enabled the swallowed error is escalated instead of
     * warned about: rethrowing here lands in `Plugin::__invoke()`'s catch, which routes
     * through {@see report()} for the full treatment (issue URL + final rethrow into
     * Psalm). A half-booted app is an internal error when the user opted into failing.
     *
     * @throws \Throwable when {@see PluginConfig::$failOnInternalError} is on
     */
    public static function reportDegradedBoot(\Throwable $throwable, Progress $output, PluginConfig $pluginConfig): void
    {
        if ($pluginConfig->failOnInternalError) {
            throw $throwable;
        }

        WarningReporter::emit(
            $output,
            "Laravel plugin: application bootstrap failed partway: {$throwable->getMessage()}",
        );

        $hint = InternalErrorClassifier::hint($throwable);
        if ($hint !== null) {
            WarningReporter::emit($output, "Laravel plugin: {$hint}");
        }

        WarningReporter::emit(
            $output,
            'Laravel plugin is running in degraded mode: service providers never booted, so '
            . 'model, facade and container inference is reduced. '
            . 'Run `vendor/bin/psalm-laravel diagnose` for details.',
        );
    }
}
