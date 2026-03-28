<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures;

/**
 * Test fixture for class constant table name resolution.
 * Used by ClassConstantTableNameTest to verify Schema::create(SomeClass::TABLE, ...) works.
 */
final class ClassWithTableConstant
{
    public const TABLE = 'users';

    public const POSTS_TABLE = 'posts';

    public const NOT_A_STRING = 42;
}
