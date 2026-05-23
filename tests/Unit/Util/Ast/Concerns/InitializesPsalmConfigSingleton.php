<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util\Ast\Concerns;

use Psalm\Config;
use Psalm\Internal\EventDispatcher;

/**
 * Plants a minimal `Psalm\Config` singleton so test paths that touch
 * `TLiteralString::make()` (reads `Config::getInstance()->max_string_length`)
 * succeed without bootstrapping the full Psalm project analyser.
 *
 * Used by the body-flow inference tests in {@see ClosureTypeFactoryTest} and
 * {@see CachedClosureTypeFactoryTest}. Heavier alternatives (loading
 * `tests/Type/psalm.xml` through `Config::loadFromXMLFile()`) pull in schema
 * validation and composer-classmap warmups that aren't relevant here.
 *
 * `tearDownAfterClass` MUST null the singleton: otherwise sibling test
 * classes (notably `NoEnvOutsideConfigHandlerTest`) that rely on the
 * "no Config initialized" precondition see a stale instance.
 */
trait InitializesPsalmConfigSingleton
{
    public static function setUpBeforeClass(): void
    {
        $rc = new \ReflectionClass(Config::class);
        $instance = $rc->newInstanceWithoutConstructor();
        $rc->getProperty('instance')->setValue(null, $instance);
        $rc->getProperty('eventDispatcher')->setValue($instance, new EventDispatcher());
    }

    public static function tearDownAfterClass(): void
    {
        (new \ReflectionClass(Config::class))->getProperty('instance')->setValue(null, null);
    }
}
