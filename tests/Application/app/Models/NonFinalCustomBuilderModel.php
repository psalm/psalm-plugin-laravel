<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\NonFinalCustomBuilder;
use Illuminate\Database\Eloquent\Model;

class NonFinalCustomBuilderModel extends Model
{
    protected $table = 'non_final_custom_builder_models';

    /** @var class-string<NonFinalCustomBuilder<static>> */
    protected static string $builder = NonFinalCustomBuilder::class;
}
