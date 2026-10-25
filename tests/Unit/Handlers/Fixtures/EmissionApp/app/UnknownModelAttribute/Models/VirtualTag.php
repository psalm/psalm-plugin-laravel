<?php

declare(strict_types=1);

namespace KnownAttributeFixture\Models;

use Illuminate\Database\Eloquent\Model;
use KnownAttributeFixture\Models\Concerns\UsesVirtualAttributes;

/** Model whose mass-assignment guard is widened by a trait: its fill path is not provable. */
class VirtualTag extends Model
{
    use UsesVirtualAttributes;

    protected $table = 'tags';

    /** @var list<string> */
    protected $fillable = ['label'];
}
