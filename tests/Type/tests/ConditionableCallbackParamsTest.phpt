--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Builders\VehicleBuilder;
use App\Models\Tool;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Stringable;
use Illuminate\Support\Traits\Conditionable;

/**
 * Conditionable::when()/unless() callback params typed from the call site by
 * ConditionableCallbackParamsHandler: `callable(<receiver>, <truthy $value>)` for the callback and
 * `callable(<receiver>, <falsy $value>)` for the default (swapped for unless()), matching Laravel's
 * `$callback($this, $value)` / `$default($this, $value)`.
 *
 * Each negative case is built so it WOULD change if its own decline gate were removed.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1624
 */

trait CallsWhenFromTrait
{
    /** `$this` inside a trait is `Host&static`; a final host gets the plain host type. */
    public function viaTrait(?int $n): void
    {
        $this->when($n, function ($self, $_v): void {
            $self->ping();
        });
    }

    /** A declared `self` stays unexpanded in closure storage: the slot keeps the stub callable. */
    public function viaTraitSelf(?int $n): void
    {
        $this->when($n, function (self $_s, ?int $x): void {
            if ($x === null) {
                return;
            }
        });
    }
}

trait FiltersWithSelf
{
    public function filterWithSelf(?string $s): void
    {
        $this->when($s, fn (self $q, string $v) => $q->apply($v));
    }
}

/** Late-bound types nested in a generic are unexpanded too: the slot keeps the stub callable. */
trait DeclaresNestedLateBound
{
    /** @param list<self>|null $items */
    public function nestedSelfList(?array $items): void
    {
        $this->when($items, /** @param list<self> $l */ function (object $_s, array $l): void {
            takes_mixed($l);
        });
    }

    /** @param Collection<int, self> $items */
    public function nestedSelfCollection(Collection $items, bool $b): void
    {
        $items->when($b, /** @param Collection<int, self> $c */ fn (Collection $c, bool $_t): Collection => $c);
    }

    /** @param Collection<int, static> $items */
    public function nestedStaticCollection(Collection $items, bool $b): void
    {
        $items->when($b, /** @param Collection<int, static> $c */ fn (Collection $c, bool $_t): Collection => $c);
    }
}

class OpenConditionableHost
{
    use Conditionable;
    use FiltersWithSelf;
    use DeclaresNestedLateBound;

    public function apply(string $_v): static
    {
        return $this;
    }
}

final class ConditionableHost
{
    use Conditionable;
    use CallsWhenFromTrait;
    use DeclaresNestedLateBound;

    public function ping(): void {}

    /** `$this->when()` inside a host types the receiver as the host itself (final, so no `&static`). */
    public function selfWhen(?int $n): void
    {
        $this->when($n, function ($q, $v): void {
            /** @psalm-check-type-exact $q = ConditionableHost */
            $q->ping();
            /** @psalm-check-type-exact $v = int */
            takes_mixed($v);
        });
    }

    /** A docblock `static` param is late-bound as well: the slot keeps the stub callable. */
    public function staticDocblock(?int $n): void
    {
        $this->when($n, /** @param static $_s */ function ($_s, int $_v): void {});
    }
}

class ConditionableParent
{
    use Conditionable;
}

interface ConditionableMarker {}

final class ConditionableChild extends ConditionableParent implements ConditionableMarker {}

final class UnrelatedToConditionable {}

function takes_mixed(mixed $_value): void {}

// --- Positive ---

/** Untyped closure params get the receiver (with generics) and the truthy value. */
function test_when_types_receiver_and_truthy_value(?int $n): void
{
    Customer::query()->when($n, function ($q, $v): void {
        /** @psalm-check-type-exact $q = Builder<Customer> */
        $q->active();
        /** @psalm-check-type-exact $v = int */
        takes_mixed($v);
    });
}

/** The default receives the falsy part of the value. */
function test_when_default_gets_falsy_value(?int $n): void
{
    Customer::query()->when($n, function ($_q, $_v): void {}, function ($q, $v): void {
        /** @psalm-check-type-exact $q = Builder<Customer> */
        $q->active();
        /** @psalm-check-type-exact $v = 0|null */
        takes_mixed($v);
    });
}

/** unless() swaps the slots: callback gets falsy, default gets truthy. */
function test_unless_swaps_truthy_and_falsy(?int $n): void
{
    Customer::query()->unless($n, function ($_q, $v): void {
        /** @psalm-check-type-exact $v = 0|null */
        takes_mixed($v);
    }, function ($_q, $v): void {
        /** @psalm-check-type-exact $v = int */
        takes_mixed($v);
    });
}

/** A Closure $value is resolved by Laravel (`$value($this)`), so its return type is what flows on. */
function test_when_closure_value_uses_its_return_type(?string $s): void
{
    Customer::query()->when(fn (): ?string => $s, function ($_q, $v): void {
        /** @psalm-check-type-exact $v = non-falsy-string */
        takes_mixed($v);
    });
}

/**
 * A string value splits into non-falsy-string / ''|'0', on a Collection receiver.
 *
 * @param Collection<int, string> $c
 */
function test_when_string_value_on_collection(Collection $c, string $s): void
{
    $c->when($s, function ($q, $v): void {
        /** @psalm-check-type-exact $q = Collection<int, string> */
        takes_mixed($q);
        /** @psalm-check-type-exact $v = non-falsy-string */
        takes_mixed($v);
    }, function ($_q, $v): void {
        /** @psalm-check-type-exact $v = ''|'0' */
        takes_mixed($v);
    });
}

/** A typed callback whose value param cannot accept the truthy value is reported. */
function test_when_typed_callback_value_mismatch_is_reported(?int $n): void
{
    Customer::query()->when($n, function (Builder $_q, string $_l): void {});
}

/** A user class using Conditionable is covered too. */
function test_when_on_user_conditionable_class(ConditionableHost $host, ?int $n): void
{
    $host->when($n, function ($q, $v): void {
        /** @psalm-check-type-exact $q = ConditionableHost */
        $q->ping();
        /** @psalm-check-type-exact $v = int */
        takes_mixed($v);
    });
}

/** Named arguments are matched by name; the value must be the first arg (see the reordered decline below). */
function test_when_named_arguments(?int $n): void
{
    Customer::query()->when(value: $n, callback: function ($_q, $v): void {
        /** @psalm-check-type-exact $v = int */
        takes_mixed($v);
    });
}

/**
 * Nullsafe call: the null part of the receiver never reaches the callback.
 *
 * @param Builder<Customer>|null $b
 */
function test_nullsafe_when_drops_null_receiver(?Builder $b, ?int $n): void
{
    $b?->when($n, function ($q, $_v): void {
        /** @psalm-check-type-exact $q = Builder<Customer> */
        $q->active();
    });
}

// --- Negative ---

/** A declared closure param that already contains the slot type keeps its declared type (no TypeDoesNotContainNull). */
function test_declared_nullable_param_is_kept(?int $n): void
{
    Customer::query()->when($n, function (Builder $_q, ?int $x): void {
        if ($x === null) {
            return;
        }
        takes_mixed($x);
    });
}

/** A dead branch (truthy of null, falsy of true) keeps the untyped stub param: no NoValue. */
function test_dead_branches_are_left_untyped(): void
{
    Customer::query()->when(null, function (Builder $_q, $v): void {
        /** @psalm-check-type-exact $v = mixed */
        takes_mixed($v);
    });

    Customer::query()->unless(true, function (Builder $_q, $v): void {
        /** @psalm-check-type-exact $v = mixed */
        takes_mixed($v);
    });
}

/** A mixed value declines entirely: the callback stays untyped. */
function test_mixed_value_declines(mixed $m): void
{
    Customer::query()->when($m, function ($q, $_v): void {
        $q->active();
    });
}

/**
 * A union of two Conditionable hosts declines.
 *
 * @param Builder<Customer>|Collection<int, string> $r
 */
function test_union_receiver_declines(Builder|Collection $r, ?int $n): void
{
    $r->when($n, function ($q, $_v): void {
        $q->count();
    });
}

/**
 * Relation forwarding dispatches on Builder but the receiver is the relation: decline (runtime
 * passes the Builder), so an untyped `$q` is not typed as the relation.
 */
function test_relation_forwarded_when_declines(Tool $tool, ?int $n): void
{
    $tool->replacementTool()->when($n, function (Builder $_q, int $_v): void {});
    $tool->replacementTool()->when($n, function ($q, int $_v): void {
        takes_mixed($q);
    });
}

/**
 * Unpacked arguments decline: positions are unknown.
 *
 * @param list{?int} $a
 * @param list{Closure(Builder<Customer>, int): void} $b
 */
function test_unpacked_arguments_decline(array $a, array $b): void
{
    Customer::query()->when(...$a, ...$b);
}

/** The 1-arg HigherOrderWhenProxy form is unchanged. */
function test_single_argument_form_unchanged(?int $n): void
{
    $_r = Customer::query()->when($n);
    /** @psalm-check-type-exact $_r = Builder<Customer>&static */
}

/**
 * Enumerable declares its own when(), so it is not a Conditionable host.
 *
 * @param Enumerable<int, string> $e
 */
function test_enumerable_receiver_unchanged(Enumerable $e, ?int $n): void
{
    $e->when($n, function ($q, $_v): void {
        $q->count();
    });
}

/** $value is analyzed on a cloned context: its side effects run once, so `$i` is 1 afterwards. */
function test_value_side_effects_apply_once(): void
{
    $i = 0;
    Customer::query()->when(++$i, fn ($q) => $q);
    /** @psalm-check-type-exact $i = 1 */
    takes_mixed($i);
}

/** @return list{int} */
function test_value_array_push_applies_once(int $id): array
{
    $ids = [];
    Customer::query()->when($ids[] = $id, fn ($q) => $q);

    return $ids;
}

/** Loop re-analysis: the declared `?int` survives Psalm's pass-1 overwrite of the closure storage. */
function test_declared_type_kept_inside_loop(?int $n): void
{
    $i = 0;
    while ($i < 10) {
        Customer::query()->when($n, function (Builder $_q, ?int $x): void {
            if ($x === null) {
                return;
            }
            takes_mixed($x);
        });
        $i++;
    }
}

/** A callable/object/Closure value may be a Closure at runtime with an unknown return: decline. */
function test_callable_value_declines(callable $c): void
{
    str('x')->when($c, fn (Stringable $x, bool $_r) => $x);
}

function test_object_value_declines(object $c): void
{
    str('x')->when($c, fn (Stringable $x, bool $_r) => $x);
}

function test_bare_closure_value_declines(\Closure $c): void
{
    str('x')->when($c, fn (Stringable $x, bool $_r) => $x);
}

/**
 * At runtime `$this` may be a custom Builder subclass Psalm cannot see: a declared subclass of the
 * receiver class is kept.
 *
 * @param Builder<Vehicle> $query
 */
function test_declared_receiver_subclass_is_kept(Builder $query, ?string $term): void
{
    $query->when($term, fn (VehicleBuilder $q, string $_t) => $q->whereElectric());
}

/**
 * A declared receiver type is trusted as written, even one unrelated to the host: runtime `$this`
 * may be any subclass or implementer, and Psalm never reported this against the stub either.
 *
 * @param Builder<Vehicle> $query
 */
function test_declared_unrelated_receiver_is_trusted(Builder $query, ?string $term): void
{
    $query->when($term, function (QueryBuilder $_q, string $_t): void {});
}

/**
 * Only closure literals get the synthesized callable; a callable passed through keeps the stub slot.
 *
 * @param callable(Builder<Customer>): void $cb
 */
function test_callable_variable_slot_unchanged(bool $b, callable $cb): void
{
    Customer::query()->when($b, $cb);
}

function test_nullable_callable_passthrough_unchanged(?callable $scope = null): void
{
    Customer::query()->when($scope, $scope);
}

function apply_electric(VehicleBuilder $q, string $_v): void
{
    $q->whereElectric();
}

/**
 * A first-class callable has no declared-type escape for a subclass receiver: stub slot kept.
 *
 * @param Builder<Vehicle> $query
 */
function test_first_class_callable_slot_unchanged(Builder $query, ?string $term): void
{
    $query->when($term, apply_electric(...));
}

/**
 * A spread followed by a named callback still declines: the value position is unknown.
 *
 * @param list{?int} $a
 */
function test_spread_with_named_callback_declines(array $a): void
{
    Customer::query()->when(...$a, callback: function (Builder $_q, int $_v): void {});
}

/**
 * The subclass escape is for the receiver slot only: a value declared as a subclass of its
 * computed type is a real coercion.
 *
 * @param Builder<Vehicle> $query
 * @param Builder<Vehicle>|null $other
 */
function test_declared_value_subclass_is_reported(Builder $query, ?Builder $other): void
{
    $query->when($other, function (Builder $_q, VehicleBuilder $_o): void {});
}


/** A variadic first param is filled from container param 0 only: the slot keeps the stub callable. */
function test_variadic_first_param_slot_unchanged(?string $s): void
{
    Customer::query()->when($s, function (string ...$args): void {
        takes_mixed($args);
    });
}

function test_variadic_first_param_with_type_guard_unchanged(?string $s): Stringable
{
    return str('x')->when($s, function (...$args): Stringable {
        foreach ($args as $a) {
            if (is_string($a)) {
                return str($a);
            }
        }

        return str('');
    });
}

/** A variadic tail after a fixed first param keeps the typed receiver. */
function test_variadic_tail_keeps_receiver(?string $s): void
{
    Customer::query()->when($s, function ($q, string ...$_rest): void {
        /** @psalm-check-type-exact $q = Builder<Customer> */
        $q->active();
    });
}

/** A nullable or union declaration naming a subclass of the receiver keeps the declared type. */
function test_nullable_declared_receiver_subclass_is_kept(): void
{
    /** @var ConditionableParent $host */
    $host = new ConditionableChild();
    $host->when(true, function (?ConditionableChild $_host): void {});
    $host->when(true, function (ConditionableChild|UnrelatedToConditionable $_host): void {});
}

/** A nullable declaration with no class related to the receiver is trusted too (see above). */
function test_nullable_declared_unrelated_receiver_is_trusted(ConditionableParent $host): void
{
    $host->when(true, function (?UnrelatedToConditionable $_host): void {});
}

/**
 * Intersection and interface declarations of the receiver are trusted as declared, in the
 * callback and default slots of both when() and unless().
 */
function test_intersection_and_interface_receiver_declarations_are_kept(): void
{
    /** @var ConditionableParent $host */
    $host = new ConditionableChild();
    $host->when(true, function ((ConditionableMarker&ConditionableChild)|null $_host): void {});
    $host->when(true, function ((ConditionableParent&ConditionableMarker)|null $_host): void {});
    $host->when(true, function (?ConditionableMarker $_host): void {});
    $host->unless(false, function (?ConditionableMarker $_host): void {});
    $host->when(false, null, function (?ConditionableMarker $_host): void {});
}

/** A `void` Closure value resolves to null at runtime (Laravel passes `$value($this)`). */
function test_void_closure_value_is_null(): void
{
    (new ConditionableParent())->unless(function (): void {}, function (ConditionableParent $_host, ?int $_value): void {});
    (new ConditionableParent())->unless(function (): void {}, function ($_host, $v): void {
        /** @psalm-check-type-exact $v = null */
        takes_mixed($v);
    });
}

/** A value arg preceded by other args (named reordering) is not pre-analyzed: their effects come first. */
function test_value_after_reordered_named_args_declines(string $value): void
{
    takes_mixed($value);
    (new ConditionableParent())->unless(
        default: ($value = null),
        value: $value,
        callback: function (ConditionableParent $_host, ?int $_value): void {},
    );
}

/** An untyped param with a default can receive the default: the slot keeps the stub callable. */
function test_untyped_param_with_default_declines(?int $value): void
{
    (new ConditionableParent())->when($value, function (ConditionableParent $_host, $value = null): void {
        if ($value === null) {
            return;
        }
        takes_mixed($value);
    });
}

/** A TYPED param with a default keeps the typed callable: the receiver is still pushed into `$q`. */
function test_typed_param_with_default_keeps_receiver(?int $n): void
{
    Customer::query()->when($n, function ($q, ?int $v = null): void {
        /** @psalm-check-type-exact $q = Builder<Customer> */
        $q->active();
        if ($v === null) {
            return;
        }
    });
}

/** A `never` Closure value leaves both branches dead: both slots keep the stub callable. */
function test_never_closure_value_leaves_slots_untyped(): void
{
    (new ConditionableParent())->when(
        function (): never {
            throw new \LogicException();
        },
        function (ConditionableParent $_host, string $_v): void {},
    );
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 2 of Illuminate\Database\Eloquent\Builder::when expects callable[impure](Illuminate\Database\Eloquent\Builder<Illuminate\Database\Eloquent\Model>, int):mixed|null, but Closure[pure](Illuminate\Database\Eloquent\Builder, string):void provided
MissingClosureParamType on line %d: Parameter $v has no provided type
MissingClosureParamType on line %d: Parameter $v has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $_v has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method active
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $_v has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method count
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $_v has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method count
ArgumentTypeCoercion on line %d: Argument 2 of Illuminate\Database\Eloquent\Builder::when expects callable[impure](Illuminate\Database\Eloquent\Builder<Illuminate\Database\Eloquent\Model>, Illuminate\Database\Eloquent\Builder<App\Models\Vehicle>):mixed|null, but parent type Closure[pure](Illuminate\Database\Eloquent\Builder, App\Builders\VehicleBuilder):void provided
MissingClosureParamType on line %d: Parameter $args has no provided type
MixedAssignment on line %d: Unable to determine the type that $a is being assigned to
MissingClosureParamType on line %d: Parameter $value has no provided type
