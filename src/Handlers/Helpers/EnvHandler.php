<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Helpers;

use Psalm\LaravelPlugin\Internal\Arg;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TNever;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TVoid;

/**
 * Narrows the return type of env() based on the default value argument.
 *
 * Laravel's env() returns the env var value when set, or the default when not set.
 * We model the "env var is set" case as string — a deliberate simplification matching
 * Larastan's approach. The runtime can also return bool for "true"/"false"/"null" string
 * values (via Env::getOption() magic parsing), but static analysis of that requires
 * knowing the env var's value at analysis time, which is not possible. Using string keeps
 * the type useful in practice without a cascade of string|bool|null everywhere.
 *
 * Narrowing rules:
 * - No default:                  string|null
 * - Default type includes null:  string|null
 * - Default type is mixed:       string|null  (mixed implicitly includes null)
 * - Default excludes null:       string|typeof(default)
 *   → A Closure default is replaced by its return type first (Env::get() calls it via value())
 *   → Default is any string subtype: collapses to string (TString covers all subtypes)
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/707
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
        $second_arg_type = Arg::typeAt($event->getCallArgs(), $event->getStatementsSource(), 1);

        // Env::get() resolves the default through value(), which calls a \Closure once (a
        // closure returning a closure keeps the inner one, a callable string is not invoked).
        if ($second_arg_type instanceof \Psalm\Type\Union) {
            $second_arg_type = self::unwrapClosureDefault($second_arg_type);
        }

        // Fall back to string|null when:
        //   - no default argument supplied (env var may not be set → null)
        //   - default has unknown type
        //   - default explicitly includes null
        //   - default is mixed (implicitly includes null)
        if (
            !$second_arg_type instanceof \Psalm\Type\Union
            || $second_arg_type->isNullable()
            || $second_arg_type->hasMixed()
        ) {
            return new Type\Union([new TString(), new TNull()]);
        }

        // Default excludes null: return string|typeof(default).
        // All string subtypes (TLiteralString, TNonEmptyString, etc.) extend TString and are
        // already covered by the TString we include, so we skip them to avoid redundant
        // string|'bar' unions — the result is just string.
        $result_types = [new TString()];

        foreach ($second_arg_type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TString) {
                $result_types[] = $atomic;
            }
        }

        return new Type\Union($result_types);
    }

    /**
     * Replaces each Closure atomic with its return type; an untyped closure becomes mixed,
     * void becomes null, never is dropped.
     *
     * @psalm-pure
     */
    private static function unwrapClosureDefault(Type\Union $default): Type\Union
    {
        $atomics = [];

        foreach ($default->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TClosure) {
                $atomics[] = $atomic;
                continue;
            }

            foreach (($atomic->return_type ?? Type::getMixed())->getAtomicTypes() as $returned) {
                // value() yields null for a void closure; a never closure never returns a value
                if ($returned instanceof TVoid) {
                    $atomics[] = new TNull();
                } elseif (!$returned instanceof TNever) {
                    $atomics[] = $returned;
                }
            }
        }

        // only never-returning closures: nothing to add, so the result is plain string
        return $atomics === [] ? new Type\Union([new TString()]) : new Type\Union($atomics);
    }
}
