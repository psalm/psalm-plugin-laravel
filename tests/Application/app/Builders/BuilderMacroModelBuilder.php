<?php

declare(strict_types=1);

namespace App\Builders;

use App\Models\BuilderMacroModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Custom builder without its own @template parameters.
 *
 * @extends Builder<BuilderMacroModel>
 */
final class BuilderMacroModelBuilder extends Builder {}
