<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Type;

use Psalm\LaravelPlugin\Internal\LaravelAiIntegration;

/**
 * laravel/ai capability gate for phpt tests exercising the optional integration stubs, called from
 * a phpt `--SKIPIF--` section.
 *
 * The integration stubs (`Plugin::optionalIntegrationStubs()`) load only when
 * `LaravelAiIntegration::isEnabled()` sees a supported laravel/ai installed. It is not a root
 * composer.json dependency (its PHP ^8.3 floor would break the PHP 8.2 CI lanes), so skip rather
 * than fail when it is absent.
 *
 * The `--SKIPIF--` script runs in a bare `php` process from the project-root working directory with
 * no autoloader preloaded, so require it via `getcwd()`:
 *
 *   --SKIPIF--
 *   <?php
 *   require getcwd() . '/vendor/autoload.php';
 *   \Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
 */
final class LaravelAiCapability
{
    /**
     * Echoes a skip reason and returns true when laravel/ai is absent or unsupported; returns false
     * (echoing nothing) when the fixture may proceed. The bool return lets a fixture that needs to
     * gate further `--SKIPIF--` logic on the same check `return` right after the call.
     */
    public static function skipUnlessInstalled(): bool
    {
        if (LaravelAiIntegration::isEnabled() && \trait_exists(\Laravel\Ai\Promptable::class)) {
            return false;
        }

        echo 'skip needs supported laravel/ai package (' . LaravelAiIntegration::CONSTRAINT . ')';

        return true;
    }
}
