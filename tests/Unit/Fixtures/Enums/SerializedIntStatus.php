<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Enums;

/**
 * Backed integer enum fixture for {@see \Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\ModelSerializationShapeBuilderTest}.
 *
 * Used as the target of a `BackedEnum` cast to verify the serialized array shape carries the
 * enum's backing scalar (`int`), not the enum case object.
 */
enum SerializedIntStatus: int
{
    case Draft = 0;
    case Published = 1;
}
