<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

use Psalm\LaravelPlugin\PluginConfig;
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
        $output->warning("Laravel plugin error on initialisation: {$throwable->getMessage()}");

        // URL generation is best-effort — a secondary failure here (e.g. a
        // throwable with a broken __toString(), a corrupt composer installed.php)
        // must never shadow the original init error that the user actually cares
        // about, so we fall back to a plain issue-tracker link. The secondary
        // error is still surfaced as a separate warning so plugin maintainers can
        // spot regressions in the URL generator during self-analysis.
        try {
            $url = IssueUrlGenerator::generate($throwable, $pluginConfig);
        } catch (\Throwable $urlGenerationFailure) {
            $output->warning("Laravel plugin failed to build a detailed report URL: {$urlGenerationFailure->getMessage()}");
            $url = 'https://github.com/psalm/psalm-plugin-laravel/issues';
        }

        $output->warning('Laravel plugin has been disabled for this run, please report about this issue: ' . $url);

        if ($pluginConfig->failOnInternalError) {
            throw $throwable;
        }
    }
}
