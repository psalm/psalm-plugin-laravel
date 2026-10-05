<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\CollectionFlatten;

use Illuminate\Support\Collection;

/** @param Collection<int, DeprecatedFlattenBox<int, string>> $boxes */
function drive_flatten(Collection $boxes): void
{
    $boxes->flatten(1);
}
