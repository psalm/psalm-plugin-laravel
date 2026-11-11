--FILE--
<?php declare(strict_types=1);

/**
 * Carbon's `Traits\Week` carries `@method static dayOfYear(int $dayOfYear)`, a setter-only
 * pseudo-method, while the real getter/setter `Traits\Date::dayOfYear()` is stubbed with a
 * conditional return. Psalm copies a trait's pseudo-methods into the using class without
 * checking for a same-name real method, and resolves the pseudo-method first, so the getter
 * collapsed to `static` on `Carbon` / `CarbonImmutable` (it was already `int<1, 366>` on
 * `CarbonInterface`). `CarbonTraitPseudoMethodHandler` drops those shadowing trait pseudo-methods.
 * Class-level `@method` tags with no real method behind them must keep working.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2253
 * @see https://github.com/vimeo/psalm/issues/3937
 */

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Psalm reports Carbon trait-vs-interface signature mismatches on any Carbon subclass (unrelated to
 * the pseudo-method precedence under test); they are listed in the expectation below.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class AppCarbon extends Carbon {}

function day_of_year(Carbon $c, CarbonImmutable $i, AppCarbon $a, CarbonInterface $n): void
{
    $_carbon = $c->dayOfYear();
    /** @psalm-check-type-exact $_carbon = int<1, 366> */

    $_immutable = $i->dayOfYear();
    /** @psalm-check-type-exact $_immutable = int<1, 366> */

    $_subclass = $a->dayOfYear();
    /** @psalm-check-type-exact $_subclass = int<1, 366> */

    $_interface = $n->dayOfYear();
    /** @psalm-check-type-exact $_interface = int<1, 366> */

    $_setter = $c->dayOfYear(5);
    /** @psalm-check-type-exact $_setter = \Carbon\Carbon&static */

    $_immutableSetter = $i->dayOfYear(5);
    /** @psalm-check-type-exact $_immutableSetter = \Carbon\CarbonImmutable&static */
}

function locale_getter(Carbon $c, CarbonInterface $n): void
{
    $_interface = $n->locale();
    /** @psalm-check-type-exact $_interface = string */

    $_carbon = $c->locale();
    /** @psalm-check-type-exact $_carbon = string */

    $_setter = $c->locale('fr');
    /** @psalm-check-type-exact $_setter = \Carbon\Carbon&static */
}

// Class-level `@method` tags have no real method behind them and must stay typed.
function class_level_tags(Carbon $c): void
{
    $_year = $c->year(2020);
    /** @psalm-check-type-exact $_year = \Carbon\Carbon&static */

    $_added = $c->addWeeks(1.5);
    /** @psalm-check-type-exact $_added = \Carbon\Carbon&static */
}
?>
--EXPECTF--
MethodSignatureMismatch on line %d: Argument 1 of Carbon\Traits\Comparison::is has wrong type %s, expecting 'string' as defined by Carbon\CarbonInterface::is
MethodSignatureMismatch on line %d: Argument 1 of Carbon\Traits\Comparison::isEndOfDay has wrong type %s, expecting 'bool' as defined by Carbon\CarbonInterface::isEndOfDay
MethodSignatureMismatch on line %d: Argument 1 of Carbon\Traits\Comparison::isStartOfDay has wrong type %s, expecting 'bool' as defined by Carbon\CarbonInterface::isStartOfDay
