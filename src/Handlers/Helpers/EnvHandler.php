<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Helpers;

use PhpParser\Node;
use PhpParser\Node\Expr;
use Psalm\LaravelPlugin\Internal\Arg;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TString;

/**
 * Narrows the return type of env() based on the default value argument.
 *
 * Laravel's env() returns the env var value when set, or the default when not set. Env::getOption()
 * maps the set values "true"/"(true)", "false"/"(false)", "null"/"(null)" and "empty"/"(empty)" to
 * true, false, null and ''. The analysed machine's env says nothing about the deployed one, so the
 * model is written for any value:
 *
 * - No default, nullable or mixed default: string|null. The null is the expected unset state and
 *   Psalm reports it (PossiblyNullArgument and friends).
 * - Non-null default: string|typeof(default)|null, string subtypes of the default collapsed into
 *   string. The null (a set "null") carries ignore_nullable_issues, Psalm's own
 *   `@psalm-ignore-nullable-return` mechanism: a guard against it (`is_string()`, `=== null`, `??`,
 *   `(string)`) before the first unguarded use is not redundant, while unguarded use of a
 *   string-default value is not reported as possibly null.
 *
 * Caveats (pinned by tests/Type/tests/ConfigEnvDefault*KnownLimitation.phpt):
 * - true/false stay out of the type: Psalm has no ignore flag for bool, and `string|bool` turned
 *   every unguarded use into InvalidScalarArgument/PossiblyInvalidOperand (#1683). A string-default
 *   value therefore still calls `is_bool()`, `=== true` and `=== false` impossible.
 * - Psalm ORs the flag when it merges unions, so a genuine null joined with such a value
 *   (`$c ? config('app.url') : null`, the values of a config array that also holds a default-less
 *   env read) is no longer reported downstream. For that reason env() in code trusts the flag of a
 *   default only when the default is a call (`env('A', env('B', 'x'))`, `env('A', config('k'))`);
 *   any other default (`$c ? env('B', 'x') : null`, even a variable holding `env('B', 'x')`) is
 *   treated as a possibly genuine null and reported.
 * - Not every Psalm check honours the flag: concatenating an int-default value reports
 *   PossiblyInvalidOperand (https://github.com/vimeo/psalm/issues/13031); a return type conditional
 *   on a null argument takes the null arm too (`redirect(config('app.url'))`, `url()`, `cookie()`,
 *   `__()`, `Carbon::tz()`); template inference drops it (`collect([config('app.url')])->map(fn (string $s)
 *   => $s)`, iterating `config()->collection()`).
 * - A truthiness check (`if ($url)`, `empty($url)`, `$url ?: 'x'`) reports
 *   RiskyTruthyFalsyComparison, as for any nullable string.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/707
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2172
 */
final class EnvHandler implements FunctionReturnTypeProviderInterface
{
    /**
     * @inheritDoc
     * @psalm-pure
     */
    #[\Override]
    public static function getFunctionIds(): array
    {
        return ['env'];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): Type\Union
    {
        $args = $event->getCallArgs();
        $default = Arg::typeAt($args, $event->getStatementsSource(), 1);

        // Psalm ORs ignore_nullable_issues when it merges unions, so a flagged default built by an
        // expression (`$c ? env('B', 'x') : null`, a variable holding it) may carry a genuine null.
        // Only a call answers with its own type, as env() and config() do.
        if ($default instanceof Type\Union && $default->ignore_nullable_issues && !self::isPositionalCall($args[1] ?? null)) {
            $default = $default->setProperties(['ignore_nullable_issues' => false]);
        }

        return self::returnTypeForDefault($default);
    }

    /** @psalm-mutation-free */
    private static function isPositionalCall(?Node\Arg $arg): bool
    {
        return $arg instanceof Node\Arg && !$arg->name instanceof Node\Identifier && !$arg->unpack
            && ($arg->value instanceof Expr\FuncCall || $arg->value instanceof Expr\StaticCall
                || $arg->value instanceof Expr\MethodCall || $arg->value instanceof Expr\NullsafeMethodCall);
    }

    /**
     * Shared with env-backed config values ({@see \Psalm\LaravelPlugin\Handlers\Config\EnvExpressionTyper}).
     *
     * @param Type\Union|null $second_arg_type null when the call passes no default
     * @psalm-pure
     */
    public static function returnTypeForDefault(?Type\Union $second_arg_type): Type\Union
    {
        // Fall back to the reported string|null when:
        //   - no default argument supplied (env var may not be set → null)
        //   - default has unknown type
        //   - default may be null (beyond the ignorable null of a nested env() read)
        //   - default is mixed (implicitly includes null)
        if (
            !$second_arg_type instanceof \Psalm\Type\Union
            || ($second_arg_type->isNullable() && !$second_arg_type->ignore_nullable_issues)
            || $second_arg_type->hasMixed()
        ) {
            return new Type\Union([new TString(), new TNull()]);
        }

        // TString covers every string subtype of the default (TLiteralString, TNonEmptyString, ...).
        $result_types = [new TString(), new TNull()];

        foreach ($second_arg_type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TString && !$atomic instanceof TNull) {
                $result_types[] = $atomic;
            }
        }

        return new Type\Union($result_types, ['ignore_nullable_issues' => true]);
    }
}
