<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\PluckValue;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/** @param Collection<int, DeprecatedPluckValue> $values */
function drive_collection_pluck(Collection $values): void
{
    $values->pluck('name');
}

/** @param array<int, DeprecatedArrPluckValue> $values */
function drive_arr_pluck(array $values): void
{
    Arr::pluck($values, 'name');
}

/** @param DeprecatedPluckBag<int, string> $bag */
function drive_arr_pluck_generic_object(DeprecatedPluckBag $bag): void
{
    Arr::pluck($bag, 'name');
}
