<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Concerns;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;

/**
 * Merges a class cast AND a fillable entry through Laravel's `initialize<Trait>()` construction hook.
 * Declared `protected` deliberately: the warm-up replay must invoke it by reflection (as Laravel does),
 * never `$instance->{$method}()` — an external dynamic call on a protected method routes to
 * Model::__call() query-builder forwarding instead of running the initializer.
 *
 * @psalm-require-extends \Illuminate\Database\Eloquent\Model
 */
trait MergesTraitConfig
{
    protected function initializeMergesTraitConfig(): void
    {
        $this->mergeCasts(['meta' => AsArrayObject::class]);
        $this->mergeFillable(['trait_fillable']);
    }
}
