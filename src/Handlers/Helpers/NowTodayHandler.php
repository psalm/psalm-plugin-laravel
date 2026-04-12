<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Helpers;

use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;

use function get_class;
use function now;

/**
 * Resolves the return type of now() and today() dynamically.
 *
 * These helpers delegate to the Date facade, whose implementation class
 * can be swapped at runtime via Date::use() — e.g. to Carbon\CarbonImmutable.
 * By calling now() at analysis time (the plugin has a booted Laravel app),
 * we capture whatever class the project has configured rather than
 * hardcoding \Illuminate\Support\Carbon.
 *
 * This mirrors Larastan's NowAndTodayExtension approach for PHPStan.
 */
final class NowTodayHandler implements FunctionReturnTypeProviderInterface
{
    /**
     * @inheritDoc
     * @psalm-pure
     */
    #[\Override]
    public static function getFunctionIds(): array
    {
        return ['now', 'today'];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): Type\Union
    {
        return new Type\Union([new TNamedObject(get_class(now()))]);
    }
}
