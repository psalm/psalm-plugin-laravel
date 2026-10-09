<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Composer\InstalledVersions;

/** @internal */
final class LaravelAiIntegration
{
    public const PACKAGE = 'laravel/ai';

    /**
     * laravel/ai 1.0 renamed, removed, and re-typed a large part of the stubbed
     * surface (Usage -> TextUsage, prompt() input union widened, Vercel protocol
     * selector, pausedProviderContentBlocks() removed), so one stub tree cannot
     * be correct for 0.11.x as well. The integration follows the 1.x line; on an
     * older release it stays off and the plugin contributes nothing rather than
     * reporting false positives against declarations that no longer exist.
     */
    public const CONSTRAINT = '>=1.0.0 <2.0.0';

    public static function isEnabled(): bool
    {
        if (!InstalledVersions::isInstalled(self::PACKAGE)) {
            return false;
        }

        return InstalledVersions::satisfies(
            new \Composer\Semver\VersionParser(),
            self::PACKAGE,
            self::CONSTRAINT,
        );
    }

    public static function installedVersion(): ?string
    {
        if (!InstalledVersions::isInstalled(self::PACKAGE)) {
            return null;
        }

        return InstalledVersions::getPrettyVersion(self::PACKAGE);
    }

    /**
     * Keep bug reports auditable without copying the integration constraint
     * into a second policy implementation.
     */
    public static function diagnostic(): string
    {
        $version = self::installedVersion();

        if ($version === null) {
            return 'disabled (laravel/ai not installed; requires ' . self::CONSTRAINT . ')';
        }

        if (self::isEnabled()) {
            return 'enabled (laravel/ai ' . $version . '; requires ' . self::CONSTRAINT . ')';
        }

        return 'disabled (laravel/ai ' . $version . ' is outside ' . self::CONSTRAINT . ')';
    }
}
