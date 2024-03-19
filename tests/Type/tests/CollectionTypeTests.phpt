--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;

final class TestStringsCollectionMethodReturnTypes
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
}
?>
--EXPECTF--
