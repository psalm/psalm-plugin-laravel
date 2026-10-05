<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\ForeignIdFor;

function drive_schema_column(Invoice $invoice): void
{
    // Typed from the migration only: the rest of the table must still parse.
    $number = $invoice->number;
    /** @psalm-check-type-exact $number = string */
}
