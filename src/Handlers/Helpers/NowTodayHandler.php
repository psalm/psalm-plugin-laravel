<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Helpers;

use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;

/**
 * Resolves the return type of now() and today() dynamically.
 *
 * These helpers delegate to the Date facade, whose implementation class
 * can be swapped at runtime via Date::use() — e.g. to Carbon\CarbonImmutable.
 * By calling the helper at analysis time (the plugin has a booted Laravel app),
 * we capture whatever class the project has configured rather than
 * hardcoding \Illuminate\Support\Carbon.
 *
 * This mirrors Larastan's NowAndTodayExtension approach for PHPStan.
 */
final class NowTodayHandler implements FunctionReturnTypeProviderInterface
{
    /**
     * Resolved date class per function ID, cached for the analysis run.
     *
     * @var array<string, class-string>
     */
    private static array $resolvedClasses = [];

    /**
     * @inheritDoc
     * @psalm-pure
     */
    #[\Override]
    public static function getFunctionIds(): array
    {
        return ['now', 'today'];
    }

    /**
     * @inheritDoc
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): Type\Union
    {
        $functionId = $event->getFunctionId();

        if (!\array_key_exists($functionId, self::$resolvedClasses)) {
            // Call the actual helper at analysis time to discover the configured date class.
            // Results are cached so Carbon is only instantiated once per function per analysis run.
            /** @psalm-suppress ImpureFunctionCall */
            $dateInstance = $functionId === 'today' ? \today() : \now();
            self::$resolvedClasses[$functionId] = \get_class($dateInstance);
        }

        return new Type\Union([new TNamedObject(
            self::$resolvedClasses[$functionId] ?? \Illuminate\Support\Carbon::class,
        )]);
    }
}
