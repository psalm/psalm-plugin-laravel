<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * The run's view data that a template's source cannot show, for the opt-in undeclared-read report
 * (#1827). A shadow is cached by its source alone, so it may only leave a name out of the `mixed`
 * net; whether the app still provides that name is decided here, per run, and the prelude's sentinel
 * ({@see ComponentViewSeedHandler}) declares the provided ones `mixed` again.
 *
 * Inactive (nothing registered, e.g. the view factory did not resolve) provides every omitted name,
 * so a run that cannot see the app state reports nothing new.
 *
 * Static because Psalm instantiates event handlers itself and hands them no plugin state.
 *
 * @internal
 */
final class UndeclaredVariableRegistry
{
    private static bool $active = false;

    /** @var array<array-key, true> names `View::share()` handed every view at boot, `app` included */
    private static array $shared = [];

    /** @var array<string, true> template path => true: a composer, creator, or Livewire view */
    private static array $declined = [];

    /** @var array<string, true> template path => true: shaped like a component view */
    private static array $componentViews = [];

    /**
     * @param array<array-key, true> $shared
     * @param array<string, true>    $declined       template path => true
     * @param array<string, true>    $componentViews template path => true
     */
    public static function register(array $shared, array $declined, array $componentViews): void
    {
        self::$active = true;
        self::$shared = $shared;
        self::$declined = $declined;
        self::$componentViews = $componentViews;
    }

    public static function isActive(): bool
    {
        return self::$active;
    }

    /**
     * The omitted names the run still provides: all of them for a declined view, and for a component
     * view no class seeded (an anonymous component hands it every attribute it was given).
     *
     * @return list<string>
     */
    public static function providedFor(string $shadowPath, ShadowEntry $entry, bool $seeded): array
    {
        $allowance = ComponentViewRegistry::undeclaredAllowanceFor($shadowPath);

        if (!self::$active
            || $allowance === null
            || isset(self::$declined[$entry->templatePath])
            || (!$seeded && isset(self::$componentViews[$entry->templatePath]))
        ) {
            return $entry->omittedVariables;
        }

        return \array_values(\array_filter(
            $entry->omittedVariables,
            static fn(string $name): bool => isset(self::$shared[$name]) || isset($allowance[$name]),
        ));
    }

    public static function reset(): void
    {
        self::$active = false;
        self::$shared = [];
        self::$declined = [];
        self::$componentViews = [];
    }
}
