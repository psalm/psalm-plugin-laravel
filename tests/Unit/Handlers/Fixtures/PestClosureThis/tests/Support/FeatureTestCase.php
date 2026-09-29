<?php

declare(strict_types=1);

namespace PestClosureThisFixture;

abstract class FeatureTestCase extends \PHPUnit\Framework\TestCase
{
    protected string $featureOnly = '';

    protected function signIn(): int
    {
        return 1;
    }
}
