--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
final class CollectionTypes
{
    /** @return Collection<int, string> */
    public function getCollection(): Collection
    {
        return new Collection(['hi']);
    }

    public function popTestNoArgs(): ?string
    {
        return $this->getCollection()->pop();
    }

    public function popTestDefaultArg(): ?string
    {
        return $this->getCollection()->pop(1);
    }

    public function firstWithoutDefaultTest(): ?string
    {
        return $this->getCollection()->first(function (string $item) {
            return strlen($item) > 3;
        });
    }

    public function firstWithScalarDefaultTest(): string|int|null
    {
        return $this->getCollection()->first(function (string $item) {
            return strlen($item) > 3;
        }, 42);
    }

    public function firstWithClosureDefaultTest(): string|int|null
    {
        return $this->getCollection()->first(function (string $item) {
            return strlen($item) > 3;
        }, function () {
            return 42;
        });
    }

    /** @return Collection<string, int> */
    public function testFlip(): Collection
    {
        return $this->getCollection()->flip();
    }

    public function lastTest(): ?string
    {
        return $this->getCollection()->last(function (string $item) {
            return strlen($item) > 3;
        });
    }

    public function getTestWithoutDefaultTest(): ?string
    {
        return $this->getCollection()->get(1);
    }

    public function getTestWithScalarDefaultTest(): string|int|null
    {
        return $this->getCollection()->get(1, 42);
    }

    public function getTestWithClosureDefaultTest(): string|int|null
    {
        return $this->getCollection()->get(1, function () {
            return 42;
        });
    }

    public function pullWithoutDefaultTest(): ?string
    {
        return $this->getCollection()->pull(1);
    }

    public function pullWitScalarDefaultTest(): string|int|null
    {
        return $this->getCollection()->pull(1, 42);
    }

    public function pullWithDefaultClosureTest(): string|int|null
    {
        return $this->getCollection()->pull(1, function () {
            return 42;
        });
    }

    /** @return int|false */
    public function searchUsingClosureTest()
    {
        return $this->getCollection()->search(function (string $item) {
            return strlen($item) > 3;
        });
    }

    public function shiftWithoutArgsTest(): ?string
    {
        return $this->getCollection()->shift();
    }

    public function shiftWithDefaultArgTest(): ?string
    {
        return $this->getCollection()->shift(1);
    }

    /** @return Collection<int, string> */
    public function shiftWithNonDefaultArgTest(): Collection
    {
        return $this->getCollection()->shift(2);
    }

    /** @return array<int, string> */
    public function allTest(): array
    {
        return $this->getCollection()->all();
    }

    /** @return Collection<int, string> */
    public function putTest(): Collection
    {
        return $this->getCollection()->put(5, 'five');
    }

    /**
     * count() returns int<0, max>, not just int.
     * This prevents false-positive ArgumentTypeCoercion when using count()
     * in arithmetic expressions passed to offsetGet.
     * @see https://github.com/psalm/psalm-plugin-laravel/issues/499
     * @psalm-check-type-exact $count = int<0, max>
     */
    public function countReturnsNonNegativeInt(): int
    {
        $count = $this->getCollection()->count();

        return $count;
    }

    public function isEmpty_assertions_works(): null
    {
        $collection = $this->getCollection();

        return $collection->isEmpty() ? $collection->first() : null;
    }

    public function isNotEmpty_assertions_works(): null
    {
        $collection = $this->getCollection();

        return $collection->isNotEmpty() ? null : $collection->first();
    }

    /**
     * empty() returns static<never, never>, assignable to any Collection type.
     * @psalm-check-type-exact $empty = Collection<never, never>
     */
    public function emptyReturnsNeverNever(): Collection
    {
        $empty = Collection::empty();

        return $empty;
    }

    /**
     * Collection<never, never> is assignable to any concrete Collection type
     * because never is the bottom type (subtype of everything).
     * @return Collection<int, string>
     */
    public function emptyIsAssignableToConcreteCollection(): Collection
    {
        return Collection::empty();
    }

    /**
     * sum() returns int|float for string-key and no-argument calls,
     * while callable callbacks narrow the return type.
     * @see https://github.com/psalm/psalm-plugin-laravel/issues/678
     */
    public function sumWithStringKey(): int|float
    {
        /** @psalm-check-type-exact $sum = float|int */
        $sum = $this->getCollection()->sum('length');

        return $sum;
    }

    /**
     * When the callback returns int, sum() narrows to int (not int|float).
     * @see https://github.com/psalm/psalm-plugin-laravel/issues/678
     */
    public function sumWithCallableReturningInt(): int
    {
        return $this->getCollection()->sum(function (string $item): int {
            return strlen($item);
        });
    }

    /**
     * When the callback returns float, sum() narrows to float.
     * @see https://github.com/psalm/psalm-plugin-laravel/issues/678
     */
    public function sumWithCallableReturningFloat(): float
    {
        return $this->getCollection()->sum(function (string $item): float {
            return (float) strlen($item);
        });
    }

    /** @see https://github.com/psalm/psalm-plugin-laravel/issues/678 */
    public function sumWithoutArguments(): int
    {
        /** @var Collection<int, int> */
        $numbers = new Collection();

        /** @psalm-check-type-exact $sum = int */
        $sum = $numbers->sum();

        return $sum;
    }

    /** @see https://github.com/psalm/psalm-plugin-laravel/issues/678 */
    public function sumWithoutArgumentsFloat(): float
    {
        /** @var Collection<int, float> */
        $numbers = new Collection();

        /** @psalm-check-type-exact $sum = float */
        $sum = $numbers->sum();

        return $sum;
    }

    /** @see https://github.com/psalm/psalm-plugin-laravel/issues/678 */
    public function sumWithoutArgumentsMixed(): int|float
    {
        /** @var Collection<int, int|float> */
        $numbers = new Collection();

        /** @psalm-check-type-exact $sum = float|int */
        $sum = $numbers->sum();

        return $sum;
    }

}

/** @var Collection<string, string> */
$collection = new Collection(["key" => "value"]);

foreach ($collection as $key => $value) {
    /** @psalm-suppress UnusedFunctionCall we need type-check only */
    substr($key, 0);

    /** @psalm-suppress UnusedFunctionCall we need type-check only */
    substr($value, 0);
}

/** @var Collection<int, string> */
$collection = new Collection(['data']);

foreach ($collection as $key => $value) {
    echo substr($value, $key);
}

?>
--EXPECT--
