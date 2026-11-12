--FILE--
<?php declare(strict_types=1);

/**
 * Trait-level `@method` tags must not shadow real Carbon methods (`Week::dayOfYear()` hid the getter).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2253
 * @see https://github.com/vimeo/psalm/issues/13545
 */

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon as IlluminateCarbon;

function day_of_year(Carbon $c, CarbonImmutable $i, IlluminateCarbon $a, CarbonInterface $n): void
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

// Class-level tags without a real method stay.
function class_level_tags(Carbon $c): void
{
    $_year = $c->year(2020);
    /** @psalm-check-type-exact $_year = \Carbon\Carbon&static */

    $_added = $c->addWeeks(1.5);
    /** @psalm-check-type-exact $_added = \Carbon\Carbon&static */
}

function other_trait_tags(Carbon $c): void
{
    // Static pseudo-method branch.
    $_testNow = Carbon::getTestNow();
    /** @psalm-check-type-exact $_testNow = \Carbon\CarbonInterface|\Closure|null */

    // The tag says `string`, the real method is nullable.
    $_json = $c->toJSON();
    /** @psalm-check-type-exact $_json = null|string */
}

// `resolveCarbon()` is protected: the call goes through `__call`, so its tag stays.
function protected_real_method(Carbon $c): void
{
    $_resolved = $c->resolveCarbon('2026-01-01');
    /** @psalm-check-type-exact $_resolved = \Carbon\Carbon&static */
}

/** @method non-empty-string realMethod() */
trait AppPseudoTag {}

final class AppClass
{
    use AppPseudoTag;

    public function realMethod(): string
    {
        return 'x';
    }
}

// The real method wins over a trait tag outside `Carbon\Traits\*` too.
function non_carbon_trait_tag(AppClass $a): void
{
    $_value = $a->realMethod();
    /** @psalm-check-type-exact $_value = string */
}

// Effective visibility: the appearing class's `use T { m as ... }` map, and the declaring method's own name.
trait AppHiddenDay
{
    protected function dayOfYear(int $dayOfYear): int
    {
        throw new \LogicException();
    }
}

trait AppPublicDay
{
    public function dayOfYear(int $dayOfYear): int
    {
        throw new \LogicException();
    }
}

trait AppNativeDay
{
    public function original(int $dayOfYear): int
    {
        throw new \LogicException();
    }
}

class AppPublicAdaptedParent
{
    use \Carbon\Traits\Week;
    use AppHiddenDay {
        dayOfYear as public;
    }
}

final class AppPublicAdaptedChild extends AppPublicAdaptedParent {}

class AppHiddenAdaptedParent
{
    use \Carbon\Traits\Week;
    use AppPublicDay {
        dayOfYear as protected;
    }

    public function __call(string $name, array $arguments): mixed
    {
        return null;
    }
}

final class AppHiddenAdaptedChild extends AppHiddenAdaptedParent
{
    public function probe(): void
    {
        // Protected by the parent's adaptation: tag stays.
        $_protected = $this->dayOfYear(5);
        /** @psalm-check-type-exact $_protected = \AppHiddenAdaptedChild */
    }
}

final class AppAliasedDay
{
    use \Carbon\Traits\Week;
    use AppNativeDay {
        original as dayOfYear;
    }
}

final class AppPrivateDay
{
    use \Carbon\Traits\Week;

    public function __call(string $name, array $arguments): mixed
    {
        return null;
    }

    private function dayOfYear(int $_dayOfYear): int
    {
        throw new \LogicException();
    }
}

function effective_visibility(AppPublicAdaptedChild $a, AppAliasedDay $c, AppPrivateDay $d): void
{
    // Made public by the parent's adaptation.
    $_public = $a->dayOfYear(5);
    /** @psalm-check-type-exact $_public = int */

    // Alias of a public method.
    $_alias = $c->dayOfYear(5);
    /** @psalm-check-type-exact $_alias = int */

    // Private real method: tag stays.
    $_private = $d->dayOfYear(5);
    /** @psalm-check-type-exact $_private = \AppPrivateDay&static */
}
?>
--EXPECTF--
