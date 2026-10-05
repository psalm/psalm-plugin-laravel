<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\AliasRelation;

use Illuminate\Database\Eloquent\Model;

// The relation's declared return type is an alias: Psalm keeps the alias in method storage, so the
// lineage check must resolve it before reading class storage.
\class_alias(\Illuminate\Database\Eloquent\Relations\HasOne::class, 'AutoloadCrashFixture\\Cases\\AliasRelation\\AliasHasOne');

final class AliasedRelationOwner extends Model
{
    /** @return AliasHasOne<AliasedRelationTarget, $this> */
    public function child(): AliasHasOne
    {
        return $this->hasOne(AliasedRelationTarget::class);
    }
}
