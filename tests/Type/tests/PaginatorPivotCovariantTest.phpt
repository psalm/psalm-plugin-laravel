--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.36.0');
--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;
use App\Models\MechanicSpecialization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;

// Laravel >= 13.36 types BelongsToMany results as `TRelatedModel&object{pivot: TPivotModel}`;
// returning them as a paginator of the plain related model needs TValue to be covariant (#1860).

/** @return LengthAwarePaginator<int, MechanicSpecialization> */
function pivot_constructed_paginator(Mechanic $mechanic): LengthAwarePaginator
{
    $specializations = $mechanic->specializations()->get();

    return new LengthAwarePaginator($specializations->forPage(1, 10), $specializations->count(), 10, 1);
}

/** @return LengthAwarePaginatorContract<int, MechanicSpecialization> */
function pivot_paginate(Mechanic $mechanic): LengthAwarePaginatorContract
{
    return $mechanic->specializations()->paginate();
}
--EXPECTF--
