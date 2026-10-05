<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\AliasRelation;

function drive_aliased_relation(AliasedRelationOwner $owner): void
{
    $child = $owner->child;
    /** @psalm-check-type-exact $child = AliasedRelationTarget|null */
}
