--FILE--
<?php declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Eloquent\Collection::map() uses a conditional return type:
 * - Returns static (Eloquent\Collection) when the callback produces Model instances
 * - Returns Support\Collection when the callback produces non-Model values (arrays, scalars)
 *
 * This mirrors Laravel's runtime behavior: map() calls toBase() when the result
 * contains non-Model values.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/500
 */
final class EloquentCollectionMapTest
{
    /** @return EloquentCollection<int, User> */
    public function getUsers(): EloquentCollection
    {
        return User::all();
    }

    /**
     * Mapping models to arrays returns Support\Collection (non-Model branch).
     *
     * @psalm-check-type-exact $result = BaseCollection<int, array{id: string}>
     */
    public function mapToArrays(): BaseCollection
    {
        $result = $this->getUsers()->map(fn (User $user): array => [
            'id' => $user->id,
        ]);

        return $result;
    }

    /**
     * Mapping models to integers returns Support\Collection (non-Model branch).
     *
     * @psalm-check-type-exact $result = BaseCollection<int, int>
     */
    public function mapToInts(): BaseCollection
    {
        $result = $this->getUsers()->map(fn (User $user): int => (int) $user->id);

        return $result;
    }

    /**
     * Mapping models to models preserves Eloquent\Collection (Model branch).
     *
     * @psalm-check-type-exact $result = EloquentCollection<int, User>&static
     */
    public function mapToModels(): EloquentCollection
    {
        $result = $this->getUsers()->map(fn (User $user): User => $user);

        return $result;
    }

    /**
     * mapWithKeys producing non-Model values returns Support\Collection.
     *
     * @psalm-check-type-exact $result = BaseCollection<string, int>
     */
    public function mapWithKeysToScalars(): BaseCollection
    {
        $result = $this->getUsers()->mapWithKeys(fn (User $user): array => [$user->id => (int) $user->id]);

        return $result;
    }

    /**
     * mapWithKeys producing Model values preserves Eloquent\Collection.
     *
     * @psalm-check-type-exact $result = EloquentCollection<string, User>&static
     */
    public function mapWithKeysToModels(): EloquentCollection
    {
        $result = $this->getUsers()->mapWithKeys(fn (User $user): array => [$user->id => $user]);

        return $result;
    }
}
?>
--EXPECTF--
