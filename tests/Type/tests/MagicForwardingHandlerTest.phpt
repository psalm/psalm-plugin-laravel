--FILE--
<?php declare(strict_types=1);

use App\Models\User;
use App\Models\Phone;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Verify that the unified MethodForwardingHandler correctly resolves return
 * types for Relation→Builder (Decorated) forwarding.
 */
final class MagicForwardingHandlerTest
{
    /** @param HasOne<Phone, User> $relation */
    public function wherePreservesRelationType(HasOne $relation): void
    {
        $_where = $relation->where('active', true);
        /** @psalm-check-type-exact $_where = HasOne<Phone, User>&static */
    }

    /** @param HasOne<Phone, User> $relation */
    public function latestPreservesRelationType(HasOne $relation): void
    {
        $_latest = $relation->latest();
        /** @psalm-check-type-exact $_latest = HasOne<Phone, User>&static */
    }

    /** @param HasOne<Phone, User> $relation */
    public function fluentChainPreservesRelationType(HasOne $relation): void
    {
        $_chain = $relation->where('active', true)->orderBy('name');
        /** @psalm-check-type-exact $_chain = HasOne<Phone, User>&static */
    }

    /** @param HasOne<Phone, User> $relation */
    public function firstReturnsModelOrNull(HasOne $relation): void
    {
        $_first = $relation->first();
        /** @psalm-check-type-exact $_first = Phone|null */
    }

    /** @param HasOne<Phone, User> $relation */
    public function firstAfterChainReturnsModelOrNull(HasOne $relation): void
    {
        $_first = $relation->where('active', true)->orderBy('name')->first();
        /** @psalm-check-type-exact $_first = Phone|null */
    }

    /** @param HasOne<Phone, User> $relation */
    public function getReturnsCollection(HasOne $relation): void
    {
        $_get = $relation->get();
        /** @psalm-check-type-exact $_get = \Illuminate\Database\Eloquent\Collection<int, Phone> */
    }
}
?>
--EXPECTF--
