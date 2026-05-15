<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\BuilderMacroModelBuilder;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Slim fixture for model-level @method annotations that describe builder macros.
 *
 * @method static BuilderMacroModelBuilder activeOnly()
 * @method static BuilderMacroModelBuilder onlyTrashed()
 * @method static Supplier unrelatedMacro()
 */
#[UseEloquentBuilder(BuilderMacroModelBuilder::class)]
final class BuilderMacroModel extends Model
{
    use SoftDeletes;

    protected $table = 'builder_macro_models';
}
