<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures;

/**
 * Test fixture for class constant column name resolution (see ClassConstantColumnNameTest).
 */
final class ClassWithColumnConstants
{
    public const TITLE = 'title';

    public const OLD = 'old_name';

    public const NEW = 'new_name';

    public const NOT_A_STRING = 42;
}
