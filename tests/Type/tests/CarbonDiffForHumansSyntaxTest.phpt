--FILE--
<?php declare(strict_types=1);

/**
 * `$syntax` is narrowed to the DIFF_* constants (Constants/DiffOptions.php:33-34 keeps bool/other ints for
 * backward compatibility only). `CarbonInterface` is not stubbed (it breaks subclass signature checks),
 * so interface-typed receivers keep Carbon's `int|array`.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2164
 */

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

function accepted(Carbon $c, CarbonImmutable $i): string
{
    return $c->diffForHumans(null, CarbonInterface::DIFF_ABSOLUTE)
        . $c->diffForHumans(null, CarbonInterface::DIFF_RELATIVE_AUTO)
        . $c->diffForHumans(null, CarbonInterface::DIFF_RELATIVE_TO_NOW)
        . $c->diffForHumans(null, CarbonInterface::DIFF_RELATIVE_TO_OTHER)
        . $c->diffForHumans()
        . $c->diffForHumans(null, null)
        . $c->diffForHumans(null, ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2])
        . $i->diffForHumans(null, CarbonInterface::DIFF_ABSOLUTE)
        . $i->diffForHumans(null, CarbonInterface::DIFF_RELATIVE_TO_OTHER)
        . $i->diffForHumans(['parts' => 2])
        . $c->from(null, CarbonInterface::DIFF_ABSOLUTE)
        . $c->ago(CarbonInterface::DIFF_ABSOLUTE)
        . $i->from(null, CarbonInterface::DIFF_RELATIVE_TO_NOW)
        . $i->ago(CarbonInterface::DIFF_RELATIVE_TO_NOW);
}

function rejected(Carbon $c, CarbonImmutable $i): string
{
    return $c->diffForHumans(null, true)
        . $c->diffForHumans(null, false)
        . $c->diffForHumans(null, 5)
        . $i->diffForHumans(null, true)
        . $i->diffForHumans(null, 5)
        . $c->from(null, true)
        . $c->ago(true)
        . $i->ago(5);
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 2 of Illuminate\Support\Carbon::diffForHumans expects 0|1|2|3|array<array-key, mixed>|null, but true provided
InvalidArgument on line %d: Argument 2 of Illuminate\Support\Carbon::diffForHumans cannot be false, 0|1|2|3|array<array-key, mixed>|null value expected
InvalidArgument on line %d: Argument 2 of Illuminate\Support\Carbon::diffForHumans expects 0|1|2|3|array<array-key, mixed>|null, but 5 provided
InvalidArgument on line %d: Argument 2 of Carbon\CarbonImmutable::diffForHumans expects 0|1|2|3|array<array-key, mixed>|null, but true provided
InvalidArgument on line %d: Argument 2 of Carbon\CarbonImmutable::diffForHumans expects 0|1|2|3|array<array-key, mixed>|null, but 5 provided
InvalidArgument on line %d: Argument 2 of Illuminate\Support\Carbon::from expects 0|1|2|3|array<array-key, mixed>|null, but true provided
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Carbon::ago expects 0|1|2|3|array<array-key, mixed>|null, but true provided
InvalidArgument on line %d: Argument 1 of Carbon\CarbonImmutable::ago expects 0|1|2|3|array<array-key, mixed>|null, but 5 provided
