<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\RelationshipProperty;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class RelationshipPropertyModel extends Model
{
    /**
     * Non-generic, and the body has no relation factory call for the registry to parse, so the
     * property type falls through to the declared-relation-class tier.
     *
     * @return DeprecatedRelationBox|HasOne
     */
    public function price(): DeprecatedRelationBox|HasOne
    {
        return $this->ownRelation();
    }

    /** @return DeprecatedGenericRelationBox<int, string>|HasOne<RelationshipPropertyModel, $this> */
    public function tag(): DeprecatedGenericRelationBox|HasOne
    {
        return $this->ownRelation();
    }

    /** @return HasOne<RelationshipPropertyModel, $this> */
    private function ownRelation(): HasOne
    {
        return $this->hasOne(self::class);
    }
}
