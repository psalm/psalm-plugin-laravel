--FILE--
<?php declare(strict_types=1);

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Contains;
use Illuminate\Validation\Rules\DoesntContain;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\NotIn;

/**
 * Issue #798: Rule::in() and siblings emitted TooManyArguments on variadic
 * calls because Laravel reads args via func_get_args() inside the body.
 *
 * Covers array, single string, variadic string, Arrayable, and UnitEnum forms.
 */

enum SortOrder: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}

function test_rule_in_with_array(Arrayable $arrayable): void
{
    $_array = Rule::in(['asc', 'desc']);
    /** @psalm-check-type-exact $_array = In */

    $_single = Rule::in('asc');
    /** @psalm-check-type-exact $_single = In */

    $_variadic = Rule::in('asc', 'desc', 'rand');
    /** @psalm-check-type-exact $_variadic = In */

    $_arrayable = Rule::in($arrayable);
    /** @psalm-check-type-exact $_arrayable = In */

    $_enum = Rule::in(SortOrder::Asc);
    /** @psalm-check-type-exact $_enum = In */
}

function test_rule_not_in(Arrayable $arrayable): void
{
    $_array = Rule::notIn(['banned', 'blocked']);
    /** @psalm-check-type-exact $_array = NotIn */

    $_single = Rule::notIn('banned');
    /** @psalm-check-type-exact $_single = NotIn */

    $_variadic = Rule::notIn('banned', 'blocked', 'removed');
    /** @psalm-check-type-exact $_variadic = NotIn */

    $_arrayable = Rule::notIn($arrayable);
    /** @psalm-check-type-exact $_arrayable = NotIn */
}

function test_rule_contains_variadic(): void
{
    $_array = Rule::contains(['foo', 'bar']);
    /** @psalm-check-type-exact $_array = Contains */

    $_variadic = Rule::contains('foo', 'bar', 'baz');
    /** @psalm-check-type-exact $_variadic = Contains */
}

function test_rule_doesnt_contain_variadic(): void
{
    $_array = Rule::doesntContain(['foo', 'bar']);
    /** @psalm-check-type-exact $_array = DoesntContain */

    $_variadic = Rule::doesntContain('foo', 'bar', 'baz');
    /** @psalm-check-type-exact $_variadic = DoesntContain */
}
?>
--EXPECTF--
