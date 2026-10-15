--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Read-only lookups (has/get/contains/search/...) take the key or value being looked up, which
 * need not be assignable to TKey/TValue: `Collection<never, never>` (bare collect(), or a union
 * with it after a ternary/if-else merge) has no assignable key at all, `collect([$a, $b])` has
 * the literal key range `int<0, 1>`, and `Collection<int, Dog>` is legitimately asked about an
 * `Animal`. The return types keep depending on TValue.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1814
 */
final class Badge extends Model
{
    public string $type = '';
}

abstract class Animal {}

final class Dog extends Animal
{
    public function isGood(): bool
    {
        return true;
    }
}

final class CollectionLookupParamsTest
{
    /** @param EloquentCollection<int, Badge> $badges */
    public function ternaryWithEmptyCollect(EloquentCollection $badges, bool $hasProgress, string $type): void
    {
        $byType = $hasProgress
            ? $badges->keyBy(static fn(Badge $b): string => $b->type)
            : collect();

        $byType->has($type);
        $byType->hasAny($type);
        $_item = $byType->get($type);
        /** @psalm-check-type-exact $_item = Badge|null */
    }

    public function bareCollect(string $type): void
    {
        $c = collect();
        $c->has($type);
        $c->hasAny([$type]);
        $_get = $c->get($type);
        $_pulled = $c->pull($type);
        $c->forget($type);
        $c->except([$type]);
        $c->only([$type]);
        $c->contains($type);
        $c->containsStrict($type);
        $_key = $c->search($type);
        isset($c[$type]);
    }

    /** @param iterable<string, int> $items */
    public function accumulatorLoop(iterable $items): void
    {
        $seen = collect();
        foreach ($items as $key => $value) {
            if ($seen->has($key)) {
                continue;
            }

            $seen->put($key, $value);
        }
    }

    public function literalKeyRange(int $int): void
    {
        $_pair = collect([new Dog(), new Dog()]);
        $_found = $_pair->get($int);
        /** @psalm-check-type-exact $_found = Dog|null */
        $_pair->has($int);
    }

    /** @param Collection<int, Dog> $dogs */
    public function widerValue(Collection $dogs, Animal $animal): void
    {
        $dogs->contains($animal);
        $dogs->containsStrict($animal);
        $_key = $dogs->search($animal);
        /** @psalm-check-type-exact $_key = false|int */
    }

    /** @param Collection<int, Dog> $dogs */
    public function callbackParamsStillInferred(Collection $dogs): void
    {
        $dogs->contains(static function ($dog, $key): bool {
            /** @psalm-check-type-exact $dog = Dog */
            /** @psalm-check-type-exact $key = int */
            return $dog->isGood() && $key > 0;
        });
        $dogs->containsStrict(static function ($dog): bool {
            /** @psalm-check-type-exact $dog = Dog */
            return $dog->isGood();
        });
        $dogs->doesntContain(static fn(Dog $dog): bool => $dog->isGood());
        $_key = $dogs->search(static function ($dog, $key): bool {
            /** @psalm-check-type-exact $dog = Dog */
            /** @psalm-check-type-exact $key = int */
            return $dog->isGood() && $key > 0;
        });
    }

    /** @param Collection<string, Badge> $badges */
    public function returnTypesStayTyped(Collection $badges, string $key): void
    {
        $_get = $badges->get($key);
        /** @psalm-check-type-exact $_get = Badge|null */

        $_getDefault = $badges->get($key, 'fallback');
        /** @psalm-check-type-exact $_getDefault = 'fallback'|Badge */

        $_getClosure = $badges->get($key, static fn(): int => 1);
        /** @psalm-check-type-exact $_getClosure = 1 */

        $_pull = $badges->pull($key);
        /** @psalm-check-type-exact $_pull = Badge|null */

        $_offset = $badges[$key];
        /** @psalm-check-type-exact $_offset = Badge */

        $_only = $badges->only([$key]);
        /** @psalm-check-type-exact $_only = Collection<string, Badge>&static */
    }
}
?>
--EXPECTF--
