<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Custom query builder for Vehicle model.
 *
 * Demonstrates the pre-Laravel 12 pattern of custom builders via newEloquentBuilder() override.
 *
 * @template TModel of Model
 * @extends Builder<TModel>
 */
class VehicleBuilder extends Builder
{
    /** @psalm-return self<TModel> */
    public function whereElectric(): self
    {
        return $this->where('fuel_type', 'electric');
    }

    /** @psalm-return self<TModel> */
    public function whereByMake(string $make): self
    {
        return $this->where('make', $make);
    }

    /** @psalm-return int<0, max> */
    public function countByMake(string $make): int
    {
        return $this->where('make', $make)->count();
    }

    /** @psalm-return TModel|null */
    public function firstByMake(string $make): ?Model
    {
        return $this->where('make', $make)->first();
    }

    /** @return Collection<int, TModel> */
    public function getByMake(string $make): Collection
    {
        return $this->where('make', $make)->get();
    }

    /** @psalm-return self<TModel>|null */
    public function maybeWhereElectric(bool $apply): ?self
    {
        return $apply ? $this->whereElectric() : null;
    }

    /**
     * @psalm-return self<TModel>
     */
    public function whereByOptions(string $make, ?int $year = null): self
    {
        return $this->where('make', $make)->when(
            $year !== null,
            static fn(Builder $query): Builder => $query->where('year', $year),
        );
    }

    /** @psalm-return self<TModel> */
    public function whereByMakes(string &$first, string ...$others): self
    {
        return $this->whereIn('make', [$first, ...$others]);
    }

    /**
     * @param-out int<0, max> $count
     * @psalm-return self<TModel>
     */
    public function withMatchCount(mixed &$count): self
    {
        $count = $this->count();

        return $this;
    }

    /**
     * @template TValue
     * @param TValue $value
     * @return TValue
     */
    public function passthrough(mixed $value): mixed
    {
        return $value;
    }

    /**
     * @template TValue of Model
     * @param TValue $value
     * @return TValue
     */
    public function modelPassthrough(Model $value): Model
    {
        return $value;
    }

    /**
     * @template TValue of Model
     * @param TValue $first
     * @param TValue $second
     * @return TValue
     */
    public function chooseModel(Model $first, Model $second): Model
    {
        return $first;
    }

    /**
     * @template TValue
     * @param TModel $model
     * @param TValue $value
     * @return TValue
     */
    public function valueForModel(Model $model, mixed $value): mixed
    {
        return $value;
    }

    /**
     * @template TValue
     * @param TValue $value
     * @return TValue
     */
    public function labelledPassthrough(string $label, mixed $value): mixed
    {
        return $value;
    }

    /**
     * @template TValue
     * @param TValue ...$values
     * @return TValue
     */
    public function lastValue(mixed ...$values): mixed
    {
        return $values[\array_key_last($values)];
    }

    public function recordQuery(): void {}
}
