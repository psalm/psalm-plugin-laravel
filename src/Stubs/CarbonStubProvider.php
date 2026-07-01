<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Stubs;

use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
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
    /**
     * Carbon major 3: CarbonPeriod `extends DatePeriodBase` and the WeekDay/Month enums exist.
     * The CI matrix only ever resolves Carbon 3, so this boundary is exercised by a unit test
     * ({@see \Tests\Psalm\LaravelPlugin\Unit\Providers\CarbonStubProviderTest}) rather than the
     * type suite.
     */
    public const CARBON_3_CONSTRAINT = '>=3.0';

    /**
     * Dual-purpose getter/setter narrowings apply to Carbon 3.0-3.11: they type params as the
     * Carbon-3 `WeekDay` enum (absent on Carbon 2), and Carbon 3.12 supersedes them with its own
     * inline conditional (#1059).
     */
    public const DUAL_PURPOSE_NARROWINGS_CONSTRAINT = '>=3.0 <3.12';

    public static function register(RegistrationInterface $registration, Progress $output): void
    {
        if (!InstalledVersions::isInstalled('nesbot/carbon')) {
            return;
        }

        $carbonRoot = InstalledVersions::getInstallPath('nesbot/carbon');

        if ($carbonRoot === null) {
            return;
        }

        $versionParser = new VersionParser();
        // Carbon 3 reshaped CarbonPeriod (`extends DatePeriodBase`) and added the WeekDay/Month
        // enums; Carbon 2 has neither. The stubs below are gated on this major so a fully supported
        // Laravel 11 + Carbon 2 install (illuminate ^11.35 resolves nesbot/carbon ^2.72 || ^3) no
        // longer hits MissingDependency / UndefinedClass (#1142).
        $isCarbon3 = InstalledVersions::satisfies($versionParser, 'nesbot/carbon', self::CARBON_3_CONSTRAINT);

        $translatorVariant = self::symfonyTranslatorHasReturnType() ? 'Strong' : 'Weak';
        $formatterVariant = self::symfonyMessageFormatterFirstParamHasType() ? 'Strong' : 'Weak';

        // Translator / MessageFormatter lazy splits exist in both Carbon 2 and 3.
        $lazyStubs = [
            $carbonRoot . "/lazy/Carbon/Translator{$translatorVariant}Type.php",

            $carbonRoot . "/lazy/Carbon/MessageFormatter/MessageFormatterMapper{$formatterVariant}Type.php",
        ];

        if ($isCarbon3) {
            // Carbon\DatePeriodBase is declared only by this lazy file, and only on Carbon 3.
            // CarbonPeriod.php:50 picks Unprotected vs Protected via `PHP_VERSION < 8.2`; the plugin
            // requires PHP 8.2+ (composer.json), so Unprotected is always correct. Carbon 2 has no
            // DatePeriodBase split (CarbonPeriod implements Iterator directly), so the file is absent
            // there — registering it would fail realpath() and warn for nothing (#1142).
            $lazyStubs[] = $carbonRoot . '/lazy/Carbon/UnprotectedDatePeriod.php';
        }

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
        //
        // The directory is split by load condition:
        //   - shared/   — version-independent stubs (the Constants/* interfaces). Always registered.
        //   - 2/ , 3/   — the Carbon-major-specific CarbonPeriod shape. Exactly one is registered,
        //                 keyed on $isCarbon3: Carbon 3 `extends DatePeriodBase` + `@implements
        //                 IteratorAggregate`, Carbon 2 `implements Iterator` directly. Shipping the
        //                 wrong shape breaks every CarbonPeriod consumer with MissingDependency (#1142).
        //   - pre-3.12/ — conditional-return narrowings for Carbon's dual-purpose getter/setter
        //                 methods. Registered ONLY for nesbot/carbon >=3.0 <3.12 (see the gate below).
        $integrationStubsDir = \dirname(__DIR__, 2) . '/stubs/integrations/carbon';

        foreach (StubFileFinder::findIn($integrationStubsDir . '/shared', $output) as $stub) {
            $registration->addStubFile($stub);
        }

        foreach (StubFileFinder::findIn($integrationStubsDir . ($isCarbon3 ? '/3' : '/2'), $output) as $stub) {
            $registration->addStubFile($stub);
        }

        // Carbon 3.12.0 gave four dual-purpose getter/setter methods an inline *param*
        // conditional `@return ($value is null ? <scalar> : static)`: isoWeekday(), weekday(),
        // utcOffset() (which carried no narrowing docblock before 3.12) and dayOfYear() (which
        // already narrowed, but via a *template* form `@psalm-return (T is int ? static : int)`).
        // Our pre-3.12/ stubs redeclare them with a *narrower* param conditional (`int<1, 7>` etc.).
        //
        // On Carbon 3.12+ both our stub and the reflected source then carry an inline param
        // conditional `@return` on the same method, and Psalm 7 (7.0.0-beta19) does not let our
        // narrower stub cleanly supersede the reflected one. Empirically the setter form
        // `isoWeekday(1)` resolves to the getter branch (`int<1, 7>`) instead of `static` (issue
        // #1059); the getter form is unaffected. The exact internal cause is an upstream Psalm
        // conditional merge/precedence defect, not something the stub can express around, so this
        // gate is the plugin-level workaround: for Carbon 3.12+ we skip our redeclarations and let
        // Carbon's own conditional drive inference (getter -> int, setter -> static, correct and
        // only marginally wider than our `int<1, 7>` getter). On Carbon < 3.12 the source carries
        // no inline param conditional that collides with ours (dayOfYear's template form does not
        // trigger the collapse), so our redeclaration sets the setter to `static` correctly
        // (verified on 3.11.4) and stays load-bearing there.
        //
        // Two methods in pre-3.12/ are NOT fully resolved by this gate. They are pre-existing,
        // out of #1059's scope, and documented here rather than silently shipped:
        //   - tz() has carried Carbon's conditional since 3.10.0 (not 3.12.0), so on Carbon
        //     3.10-3.11 our redeclaration still double-conditions it and the setter `tz('UTC')`
        //     collapses to `string`. The gate skips it correctly on 3.12+; a complete fix would
        //     gate tz() at `< 3.10` (or drop the redeclaration). Note that a `< 3.10` gate cannot
        //     be a sibling directory: tz() shares Date.phpstub / CarbonInterface.phpstub with the
        //     `< 3.12` methods, so a second boundary would need tz() split into its own stub file.
        //   - locale() resolves to the union `static|string` (setter not narrowed to `static`)
        //     even in pure Carbon 3.12.2 with our stub gated off, because Carbon declares the
        //     conditional on BOTH the Localization trait and CarbonInterface (plus a variadic
        //     tail param). That is an upstream Carbon/Psalm interaction the plugin cannot fix by
        //     gating.
        //
        // The gate's lower bound is >=3.0, not "everything below 3.12": these stubs type the
        // dual-purpose params as Carbon\WeekDay, an enum added in Carbon 3.0 that does not exist on
        // Carbon 2. Registering them on Carbon 2 injects a dangling Carbon\WeekDay reference and
        // forces a Carbon-3 signature onto Carbon 2's untyped methods (UndefinedClass at WeekDay
        // use-sites, #1142). Carbon 2 keeps its own `@return static|int` instead.
        if (InstalledVersions::satisfies($versionParser, 'nesbot/carbon', self::DUAL_PURPOSE_NARROWINGS_CONSTRAINT)) {
            foreach (StubFileFinder::findIn($integrationStubsDir . '/pre-3.12', $output) as $stub) {
                $registration->addStubFile($stub);
            }
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
            if (!\interface_exists(\Symfony\Component\Translation\Formatter\MessageFormatterInterface::class)) {
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
