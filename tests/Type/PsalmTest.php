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
        return PsalmTester::create()
            ->withConfig(__DIR__ . \DIRECTORY_SEPARATOR . 'psalm.xml')
            ->withArguments('--no-progress', '--no-diff');
    }
}
