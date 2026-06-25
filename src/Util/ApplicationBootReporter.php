<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\Progress\Progress;

/** @internal */
final class ApplicationBootReporter
{
    public static function reportPartialBoot(Progress $output): void
    {
        $bootstrapError = ApplicationProvider::getBootstrapError();

        if (!$bootstrapError instanceof \Throwable) {
            return;
        }

        $output->warning(self::partialBootMessage($bootstrapError));
    }

    /** @psalm-pure */
    public static function hardFailureNextSteps(): string
    {
        return 'Run `vendor/bin/psalm-laravel diagnose --tips --providers` for a Laravel boot report. '
            . 'If `php artisan` fails with the same error, fix the application bootstrap first '
            . '(usually config, .env, or a service provider).';
    }

    /** @psalm-external-mutation-free */
    public static function partialBootMessage(\Throwable $bootstrapError): string
    {
        return 'Laravel plugin: Laravel boot completed only partially'
            . self::bootContext()
            . '. Laravel initialization threw ' . $bootstrapError::class . ': ' . $bootstrapError->getMessage()
            . '. Psalm will continue with degraded Laravel inference; container-dependent features '
            . 'may be incomplete until the application bootstrap is fixed. '
            . self::hardFailureNextSteps();
    }

    /** @psalm-external-mutation-free */
    private static function bootContext(): string
    {
        $context = [];

        $bootMode = ApplicationProvider::getBootMode();
        if ($bootMode !== null) {
            $context[] = "mode: {$bootMode}";
        }

        $bootPath = ApplicationProvider::getBootPath();
        if ($bootPath !== null) {
            $context[] = "path: {$bootPath}";
        }

        if ($context === []) {
            return '';
        }

        return ' (' . \implode(', ', $context) . ')';
    }
}
