<?php

declare(strict_types=1);

namespace ColumnListFixture\migrations;

use KnownAttributeFixture\Models\KnownThing;

/** A file under a `migrations` directory is point-in-time code and is skipped. */
function backfill(): void
{
    KnownThing::query()->get(['silentcol_migration_file']);
}
