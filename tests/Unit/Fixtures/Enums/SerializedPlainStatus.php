<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Enums;

/**
 * Plain (non-backed) enum fixture for {@see \Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\ModelSerializationShapeBuilderTest}.
 *
 * Used as the target of an enum cast to verify the serialized array shape carries the case's
 * `->name` (`string`), matching `HasAttributes::getStorableEnumValue()` / `enum_value()`, not the
 * enum case object.
 */
enum SerializedPlainStatus
{
    case Draft;
    case Published;
}
