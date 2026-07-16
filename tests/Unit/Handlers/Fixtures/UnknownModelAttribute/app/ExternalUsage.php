<?php

declare(strict_types=1);

namespace KnownAttributeFixture;

use KnownAttributeFixture\Models\KnownThing;

/** The ordinary (non-self/static) external receiver form must still be flagged. */
function create_with_typo_from_outside(): KnownThing
{
    return KnownThing::create(['bad_key' => 1, 'name' => 'Ada', 'email' => 'a@b.com']);
}

/** A clean external call must never be flagged. */
function create_clean_from_outside(): KnownThing
{
    return KnownThing::create(['name' => 'Ada', 'email' => 'a@b.com']);
}
