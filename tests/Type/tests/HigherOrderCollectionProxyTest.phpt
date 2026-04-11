--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class TeamMember extends Model
{
    public function getTotalCompletedTimeInHours(): int
    {
        return 42;
    }

    public function getAmount(): float
    {
        return 1.5;
    }
}

/** @var EloquentCollection<int, TeamMember> $members */
$members = new EloquentCollection();

// sortByDesc->method() must return Enumerable<TKey, TValue> — previously Psalm inferred
// int via @mixin TValue and then failed on ->values() called on int (InvalidMethodCall).
/** @psalm-check-type-exact $proxyResult = \Illuminate\Support\Enumerable<int, TeamMember> */
$proxyResult = $members->sortByDesc->getTotalCompletedTimeInHours();
// Chaining a collection method on the result must not produce InvalidMethodCall.
$_ = $proxyResult->values();

// TKey propagates through the proxy (string-keyed collection).
/** @var EloquentCollection<string, TeamMember> $stringKeyed */
$stringKeyed = new EloquentCollection();

/** @psalm-check-type-exact $proxyStringResult = \Illuminate\Support\Enumerable<string, TeamMember> */
$proxyStringResult = $stringKeyed->sortByDesc->getTotalCompletedTimeInHours();
$_ = $proxyStringResult->values();

// map->method() chaining works without error.
/** @var Collection<int, string> $strings */
$strings = new Collection(['a', 'b']);

/** @psalm-check-type-exact $mappedResult = \Illuminate\Support\Enumerable<int, string> */
$mappedResult = $strings->map->strtoupper();
$_ = $mappedResult->values();

// Aggregate proxies (sum, avg, max) also return Enumerable — documented trade-off.
// Scalar precision is lost, but these are rarely chained further and the alternative
// (@mixin TValue resolution returning the item method's type) was equally wrong.
/** @psalm-check-type-exact $sumResult = \Illuminate\Support\Enumerable<int, TeamMember> */
$sumResult = $members->sum->getAmount();

// Consume to suppress UnusedVariable in strict test mode.
echo get_class($members) . get_class($strings) . get_class($stringKeyed);
echo $sumResult->count();

?>
--EXPECTF--
