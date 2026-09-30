<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Composer\InstalledVersions;

/** @internal */
final class LaravelAiIntegration
{
    public const PACKAGE = 'laravel/ai';

    public const CONSTRAINT = '>=0.11.0 <2.0.0';

    /**
     * laravel/ai 1.0 renamed, removed, and re-typed a large part of the stubbed
     * surface (Usage -> TextUsage, prompt() input union widened, Vercel protocol
     * selector, pausedProviderContentBlocks() removed). One declaration cannot be
     * correct on both majors, so the conflicting stubs are split per major and
     * only one variant directory is ever registered.
     */
    private const V1_CONSTRAINT = '>=1.0.0';

    /** Subdirectory of `stubs/integrations/laravel-ai/` holding the major-specific stubs. */
    public const STUB_VARIANT_V1 = 'v1';

    /** @see self::STUB_VARIANT_V1 */
    public const STUB_VARIANT_PRE_V1 = 'pre-1.0';

    public static function isEnabled(): bool
    {
        if (!InstalledVersions::isInstalled(self::PACKAGE)) {
            return false;
        }

        return self::satisfies(self::CONSTRAINT);
    }

    /**
     * Which major-specific stub directory the installed release needs. Only
     * meaningful once {@see self::isEnabled()} is true.
     */
    public static function stubVariantDirectory(): string
    {
        return self::satisfies(self::V1_CONSTRAINT) ? self::STUB_VARIANT_V1 : self::STUB_VARIANT_PRE_V1;
    }

    private static function satisfies(string $constraint): bool
    {
        return InstalledVersions::satisfies(
            new \Composer\Semver\VersionParser(),
            self::PACKAGE,
            $constraint,
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
            // The variant tells a bug report which stub tree was actually loaded,
            // which the version alone no longer implies now that two majors are supported.
            return 'enabled (laravel/ai ' . $version . ', stubs ' . self::stubVariantDirectory()
                . '; requires ' . self::CONSTRAINT . ')';
        }

        return 'disabled (laravel/ai ' . $version . ' is outside ' . self::CONSTRAINT . ')';
    }
}
