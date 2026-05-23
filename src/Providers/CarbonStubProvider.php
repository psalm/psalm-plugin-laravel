<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use Composer\InstalledVersions;
use Psalm\LaravelPlugin\Util\StubFileFinder;
use Psalm\Plugin\RegistrationInterface;
use Psalm\Progress\Progress;

/**
 * Registers Carbon's "lazy" class definitions as Psalm stubs.
 *
 * Carbon declares three classes via runtime `require` from vendor/nesbot/carbon/lazy/,
 * picking between pairs of files based on PHP and Symfony versions:
 *
 * - Carbon\DatePeriodBase                           — CarbonPeriod.php:50 (PHP_VERSION < 8.2)
 * - Carbon\LazyTranslator                           — Translator.php:20-29 (Translator::trans() return type)
 * - Carbon\MessageFormatter\LazyMessageFormatter    — MessageFormatterMapper.php:21-25 (format() first-param type)
 *
 * The `lazy/` directory is not in Carbon's composer autoload, so Psalm cannot resolve
 * these classes statically. Without stubs, Psalm reports `MissingDependency` on
 * `CarbonPeriod`, `Translator`, and `MessageFormatterMapper`.
 *
 * Carbon ships an `extension.neon` for PHPStan (`bootstrapFiles: UnprotectedDatePeriod.php`)
 * but no Psalm equivalent. We register Carbon's own lazy files as stubs — no class
 * duplication, automatically inheriting any upstream changes.
 *
 * Variant selection mirrors Carbon's own runtime choice so the plugin stays in sync with
 * the variant actually loaded at runtime (important when older Symfony pins
 * `translation-contracts` 2.x, which lacks the return type on `trans()`).
 *
 * @internal
 */
final class CarbonStubProvider
{
    public static function register(RegistrationInterface $registration, Progress $output): void
    {
        if (! InstalledVersions::isInstalled('nesbot/carbon')) {
            return;
        }

        $carbonRoot = InstalledVersions::getInstallPath('nesbot/carbon');

        if ($carbonRoot === null) {
            return;
        }

        $translatorVariant = self::symfonyTranslatorHasReturnType() ? 'Strong' : 'Weak';
        $formatterVariant = self::symfonyMessageFormatterFirstParamHasType() ? 'Strong' : 'Weak';

        $lazyStubs = [
            // Carbon\DatePeriodBase — picked at CarbonPeriod.php:50 via `PHP_VERSION < 8.2`.
            // Plugin requires PHP 8.2+ (composer.json), so Unprotected is always correct.
            $carbonRoot . '/lazy/Carbon/UnprotectedDatePeriod.php',

            $carbonRoot . "/lazy/Carbon/Translator{$translatorVariant}Type.php",

            $carbonRoot . "/lazy/Carbon/MessageFormatter/MessageFormatterMapper{$formatterVariant}Type.php",
        ];

        foreach ($lazyStubs as $stub) {
            $realPath = \realpath($stub);

            if ($realPath !== false) {
                // realpath() is load-bearing. InstalledVersions::getInstallPath() can return
                // a path containing `..` segments (e.g. `vendor/composer/../nesbot/carbon`).
                // When Psalm scans the stub it hits `if (!class_exists(X)) { class X {} }`,
                // calls PHP's class_exists (which returns true because earlier plugin boot
                // autoloaded Carbon\CarbonPeriod, whose top-level `require` already declared
                // X), and compares ReflectionClass::getFileName() to the stub's $file_path.
                // If those paths differ only by `..` normalisation, Psalm assumes the class
                // lives elsewhere, sets skip_if_descendants, and the guarded class declaration
                // is silently dropped. Downstream code then sees MissingDependency. See #922.
                $registration->addStubFile($realPath);
            } else {
                $output->warning(
                    "Laravel plugin: Carbon lazy stub not found at '{$stub}'. "
                    . 'CarbonPeriod / Translator / MessageFormatterMapper types may not resolve.',
                );
            }
        }

        // Plugin-shipped stubs for Carbon's public API live in stubs/integrations/carbon/.
        // They are kept outside stubs/common/ because Carbon is not part of Laravel
        // (it ships as a transitive dependency via illuminate/support) and downstream
        // projects can pin different Carbon majors. Loading is gated on the
        // `nesbot/carbon` install check above so the plugin does not register stubs for
        // a package the consumer does not actually use.
        $integrationStubsDir = \dirname(__DIR__, 2) . '/stubs/integrations/carbon';

        foreach (StubFileFinder::findIn($integrationStubsDir, $output) as $stub) {
            $registration->addStubFile($stub);
        }
    }

    /**
     * Mirror of Carbon\Translator.php:20-25 — Carbon's ternary reads `class_exists(TranslatorInterface::class)`
     * which is always false for interfaces (PHP returns false from class_exists() for interfaces),
     * so Carbon effectively always reflects the concrete `Symfony\Component\Translation\Translator`
     * class. We reflect the same class so our stub choice stays in lockstep with Carbon's runtime
     * pick even when translation-contracts is pinned at an older major than symfony/translation.
     */
    private static function symfonyTranslatorHasReturnType(): bool
    {
        try {
            if (\class_exists(\Symfony\Component\Translation\Translator::class)) {
                return (new \ReflectionMethod(
                    \Symfony\Component\Translation\Translator::class,
                    'trans',
                ))->hasReturnType();
            }
        } catch (\ReflectionException) {
            // Fall through to the default.
        }

        // Symfony not installed or reflection failed — default to the modern (strong) variant.
        return true;
    }

    /**
     * Mirror of Carbon\MessageFormatter\MessageFormatterMapper.php:21-25 — picks the "strong type"
     * variant when Symfony's MessageFormatterInterface::format() first parameter is typed.
     */
    private static function symfonyMessageFormatterFirstParamHasType(): bool
    {
        try {
            if (! \interface_exists(\Symfony\Component\Translation\Formatter\MessageFormatterInterface::class)) {
                return true;
            }

            $params = (new \ReflectionMethod(
                \Symfony\Component\Translation\Formatter\MessageFormatterInterface::class,
                'format',
            ))->getParameters();

            return isset($params[0]) && $params[0]->hasType();
        } catch (\ReflectionException) {
            return true;
        }
    }
}
