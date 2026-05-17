<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

/**
 * Identifies which branch of {@see ApplicationProvider::doGetApp()} resolved
 * the Laravel app during plugin boot.
 *
 * Exposed so `bin/psalm-laravel diagnose` can report whether the user's real
 * kernel was picked up, whether a vendor-relative bootstrap was discovered,
 * or whether Testbench's bundled skeleton was used as a fallback (#766).
 *
 * @internal
 */
enum BootMode: string
{
    /**
     * `bootstrap/app.php` discovered relative to the current working directory.
     *
     * Standard application or local-dev scenario.
     */
    case UserKernel = 'user_kernel';

    /**
     * `bootstrap/app.php` discovered relative to the plugin's vendor location
     * (`vendor/psalm/plugin-laravel/`).
     *
     * Triggers when Psalm is invoked from a sub-directory deeper than the
     * project root but with the plugin installed in a recognisable vendor tree.
     */
    case VendorBootstrap = 'vendor_bootstrap';

    /**
     * Testbench-provided skeleton was used because no real `bootstrap/app.php`
     * could be located. The plugin runs but only sees the package's own code
     * (#766 — the issue this diagnose mode is meant to surface).
     */
    case TestbenchFallback = 'testbench_fallback';

    /** @psalm-mutation-free */
    public function describe(): string
    {
        return match ($this) {
            self::UserKernel => 'user kernel (real bootstrap/app.php discovered)',
            self::VendorBootstrap => 'vendor-relative bootstrap (plugin installed in vendor/)',
            self::TestbenchFallback => 'Testbench fallback (no real bootstrap/app.php found — #766)',
        };
    }
}
