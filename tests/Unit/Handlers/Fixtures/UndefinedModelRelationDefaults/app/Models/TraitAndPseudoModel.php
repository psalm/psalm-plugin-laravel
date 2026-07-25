<?php

declare(strict_types=1);

namespace RelationDefaultsFixture\Models;

use Illuminate\Database\Eloquent\Model;
use RelationDefaultsFixture\Concerns\HasTraitRelation;

/** @method static pseudoRelation() */
final class TraitAndPseudoModel extends Model
{
    use HasTraitRelation;

    protected $with = ['traitRelation', 'pseudoRelation'];
}
