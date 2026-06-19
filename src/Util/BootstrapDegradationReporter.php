<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

use Psalm\Progress\Progress;

/**
 * Surfaces a *tolerated* (swallowed) Laravel bootstrap failure as a loud warning.
 *
 * {@see \Psalm\LaravelPlugin\Providers\ApplicationProvider::doGetApp()} deliberately
 * catches a throwable from `$consoleApp->bootstrap()` — typically a `config/*.php` file
 * that fatals during evaluation (e.g. `parse_url(env('UNSET_VAR'))` returning null on
 * PHP 8.1+) — so one bad config file does not disable the plugin for the whole run.
 *
 * The cost of that crash-resistance is that the app is left *partially booted*: the later
 * bootstrappers (RegisterFacades, RegisterProviders, BootProviders) never ran, so the
 * plugin has no service-provider bindings, no model metadata, and no facade map. Handlers
 * then run effectively inert and Psalm reports a misleadingly clean run — the failure used
 * to be observable only via `bin/psalm-laravel diagnose` (#1096).
 *
 * This reporter makes that degraded state visible from a normal `psalm` run without
 * sacrificing crash-resistance. It is intentionally **warn-only**: escalating a degraded
 * boot to a hard failure is the caller's decision, gated on
 * {@see \Psalm\LaravelPlugin\Config\PluginConfig::$failOnInternalError} in
 * {@see \Psalm\LaravelPlugin\Plugin::__invoke()}.
 *
 * Sibling of {@see InternalErrorReporter} (which handles the plugin-is-fully-disabled case);
 * both compose {@see InternalErrorClassifier} to point the user at the most likely fix site.
 *
 * Visibility note: `Progress::warning()` writes to STDERR under Psalm's default progress
 * renderer, so the message shows in a normal run. `--no-progress` swaps in `VoidProgress`,
 * which swallows it — the same pre-existing blind spot {@see InternalErrorReporter} has.
 * CI users who run `--no-progress` should pair it with `failOnInternalError`, which fails
 * the run through a channel that is visible regardless of progress mode.
 *
 * @internal
 */
final class BootstrapDegradationReporter
{
    public static function warn(\Throwable $bootstrapError, Progress $output): void
    {
        $output->warning(
            'Laravel plugin: running in DEGRADED mode. Your application booted only partially because '
            . 'bootstrap() threw and the plugin tolerated it. Later boot stages were skipped, so configured '
            . 'service providers, facade bindings, and migration schema can be missing, which produces '
            . 'false-positive Undefined*/Mixed* findings and lower type coverage than a healthy boot. '
            . 'Cause: ' . $bootstrapError->getMessage(),
        );

        // Best-effort classification — points at the most likely fix site (the user's
        // config/provider, the framework, Testbench, or the plugin itself).
        $hint = InternalErrorClassifier::hint($bootstrapError);
        if ($hint !== null) {
            $output->warning('Laravel plugin: ' . $hint);
        }

        $output->warning(
            'Laravel plugin: run `vendor/bin/psalm-laravel diagnose` for the full boot report. '
            . 'Set <failOnInternalError value="true" /> in your psalm.xml plugin config to fail the run '
            . 'instead of degrading silently (recommended for CI).',
        );
    }
}
