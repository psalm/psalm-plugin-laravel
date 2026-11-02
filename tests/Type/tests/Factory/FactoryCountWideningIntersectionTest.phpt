--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\CountWideningIntersection;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Widening a failed `static` replaces only the factory atomic's count: declared intersection members and `&static`
// survive, at any depth.

final class User extends Model
{
    use HasFactory;
}

interface Marker
{
    /** @psalm-pure */
    public function marker(): int;
}

/** @extends Factory<User> */
class F extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    /** @return F&Marker&static */
    public function marked()
    {
        if (!$this instanceof Marker) {
            throw new \LogicException();
        }

        return $this;
    }

    /** @return list<F&Marker&static> */
    public function markedList()
    {
        if (!$this instanceof Marker) {
            throw new \LogicException();
        }

        return [$this];
    }
}

final class MarkedChild extends F implements Marker
{
    /** @psalm-pure */
    #[\Override]
    public function marker(): int
    {
        return 42;
    }
}

/** @param F<null> $f */
function widened(F $f): void
{
    $_marker = $f->marked()->marker();
    /** @psalm-check-type-exact $_marker = int */;

    foreach ($f->markedList() as $item) {
        $_item = $item->marker();
        /** @psalm-check-type-exact $_item = int */;
    }

    $_made = $f->marked()->make();
    /** @psalm-check-type-exact $_made = User|Collection<int, User> */;
}

widened(new MarkedChild());
?>
--EXPECTF--
