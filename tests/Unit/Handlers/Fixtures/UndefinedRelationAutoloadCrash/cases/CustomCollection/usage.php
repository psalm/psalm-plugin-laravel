<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\CustomCollection;

use Illuminate\Pagination\LengthAwarePaginator;

/** @param LengthAwarePaginator<int, CustomCollectionModel|DeprecatedCustomCollectionValue> $page */
function drive_paginator_collection(LengthAwarePaginator $page): void
{
    $page->getCollection();
}
