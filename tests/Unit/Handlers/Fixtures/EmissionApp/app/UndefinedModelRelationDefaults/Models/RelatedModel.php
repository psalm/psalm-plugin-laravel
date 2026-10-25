<?php

declare(strict_types=1);

namespace RelationDefaultsFixture\Models;

use Illuminate\Database\Eloquent\Model;

final class RelatedModel extends Model
{
    public function nestedRelation(): void {}
}
