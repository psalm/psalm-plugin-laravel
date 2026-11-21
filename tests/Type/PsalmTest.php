<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Type;

use AliesDev\PsalmTester\PsalmPhptTestCase;
use AliesDev\PsalmTester\PsalmTester;

final class PsalmTest extends PsalmPhptTestCase
{
    #[\Override]
    protected static function phptDirectory(): string
    {
        return __DIR__ . \DIRECTORY_SEPARATOR . 'tests';
    }

    #[\Override]
    protected static function tester(): PsalmTester
    {
        // Since Psalm 7.14 a function counts as defined only if the composer.json next to the config
        // requires the package declaring it, and tests/Type has no composer.json of its own: without one,
        // every Laravel helper not stubbed here (now(), cache(), env(), trans()) is an UndefinedFunction.
        // A differently named file keeps the Laravel boot (project root, vendor providers) as it was.
        return PsalmTester::create()
            ->withConfig(__DIR__ . \DIRECTORY_SEPARATOR . 'psalm.xml')
            ->withEnv(['COMPOSER' => 'composer.fixture.json'])
            ->withArguments('--no-progress', '--no-diff');
    }
}
