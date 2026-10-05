<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\ToArrayCasts;

function serialize_casts(ToArrayCastModel $model): void
{
    // An enum cast serializes to its backing value; a class cast keeps its caster's type over the accessor.
    $shape = $model->toArray();
    /** @psalm-check-type-exact $shape = array{price?: Money, status?: string, ...<string, mixed>} */
}
