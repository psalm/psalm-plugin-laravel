<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Helpers;

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
 *   env read) is no longer reported downstream.
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
        return self::returnTypeForDefault(Arg::typeAt($event->getCallArgs(), $event->getStatementsSource(), 1));
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
