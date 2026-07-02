<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\CovariantNonFinalCustomBuilder;
use Illuminate\Database\Eloquent\Model;

class CovariantNonFinalCustomBuilderModel extends Model
{
    protected $table = 'covariant_non_final_custom_builder_models';

    /** @var class-string<CovariantNonFinalCustomBuilder<static>> */
    protected static string $builder = CovariantNonFinalCustomBuilder::class;

    public static function viaQuery(): static
    {
        return static::query()->firstOrCreate(['id' => '1']);
    }

    public function viaNewQuery(): static
    {
        return $this->newQuery()->firstOrCreate(['id' => '1']);
    }

    public function viaNewModelQuery(): static
    {
        return $this->newModelQuery()->firstOrNew(['id' => '1']);
    }

    public function viaNewQueryWithoutRelationships(): static
    {
        return $this->newQueryWithoutRelationships()->create(['id' => '1']);
    }

    public function viaNewQueryWithoutScopes(): static
    {
        return $this->newQueryWithoutScopes()->firstOrCreate(['id' => '1']);
    }

    public function viaNewQueryWithoutScope(): static
    {
        return $this->newQueryWithoutScope('tenant')->firstOrNew(['id' => '1']);
    }

    public function viaNewQueryForRestoration(): static
    {
        return $this->newQueryForRestoration(1)->firstOrCreate(['id' => '1']);
    }
}
