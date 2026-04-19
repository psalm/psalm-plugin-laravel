<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli;

/**
 * Psalm plugins that meaningfully improve analysis when a sibling Composer
 * dependency is in use. `init` detects these and either auto-wires them into
 * the generated psalm.xml (when already installed) or prompts/hints the user
 * to install them.
 *
 * Adding a new case here is the only change needed to teach `init` about
 * another companion plugin.
 */
enum CompanionPlugin: string
{
    case PhpUnit = 'phpunit';
    case Mockery = 'mockery';

    /**
     * The Composer package whose presence triggers this companion. The
     * companion is only suggested when this package is declared in the
     * project's composer.json (require or require-dev).
     *
     * @psalm-mutation-free
     */
    public function dependency(): string
    {
        return match ($this) {
            self::PhpUnit => 'phpunit/phpunit',
            self::Mockery => 'mockery/mockery',
        };
    }

    /**
     * The Psalm companion plugin package to install.
     *
     * @psalm-mutation-free
     */
    public function pluginPackage(): string
    {
        return match ($this) {
            self::PhpUnit => 'psalm/phpunit-plugin',
            self::Mockery => 'psalm/mockery-plugin',
        };
    }

    /**
     * The FQCN registered as a <pluginClass> in psalm.xml.
     *
     * @psalm-mutation-free
     */
    public function pluginClass(): string
    {
        return match ($this) {
            self::PhpUnit => 'Psalm\\PhpUnitPlugin\\Plugin',
            self::Mockery => 'Psalm\\MockeryPlugin\\Plugin',
        };
    }

    /**
     * Display name used in CLI output.
     *
     * @psalm-mutation-free
     */
    public function friendlyName(): string
    {
        return match ($this) {
            self::PhpUnit => 'PHPUnit',
            self::Mockery => 'Mockery',
        };
    }
}
