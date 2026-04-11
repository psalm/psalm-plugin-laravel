<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Helpers;

use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TString;

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
        $call_args = $event->getCallArgs();

        // No default argument — env var may not be set → null
        if (\count($call_args) < 2) {
            return new Type\Union([new TString(), new TNull()]);
        }

        $second_arg_type = $event->getStatementsSource()
            ->getNodeTypeProvider()
            ->getType($call_args[1]->value);

        // Unknown type, default includes null, or default is mixed (implicitly includes null):
        // fall back to string|null
        if ($second_arg_type === null
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
}
