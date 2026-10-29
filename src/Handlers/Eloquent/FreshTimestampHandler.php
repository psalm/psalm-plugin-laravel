<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Model;
use Psalm\LaravelPlugin\Internal\ConfiguredDateClass;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Types `Model::freshTimestamp()` as the app's configured date class.
 *
 * Laravel documents the method as `@return \Illuminate\Support\Carbon`, but it returns `Date::now()`, so a
 * `Date::use(CarbonImmutable::class)` app gets a `CarbonImmutable`. {@see ConfiguredDateClass} answers the
 * class (the same one model casts, pivot timestamps and the `Date` facade use); `mixed` when it is known not
 * to be a Carbon.
 *
 * Psalm dispatches a method return-type provider by exact class name, and asks for the receiver's class first, then
 * for the class declaring the method. `HasTimestamps` is a trait, so a model, `$this` in a model and an abstract
 * subclass reach this handler with the trait as the declaring class, while a receiver typed exactly `Model` reaches it
 * under `Model` itself: both are registered. An override in the model, a parent or a trait it uses declares the method
 * on that class instead, so this handler is never asked and the override keeps its own declared type. A class that
 * declares its own `freshTimestamp()` is never asked either; one that merely uses `HasTimestamps` gets the trait's
 * `Date::now()`, which is what it returns.
 *
 * `freshTimestampString()` returns a string and is deliberately not covered.
 *
 * @internal
 */
final class FreshTimestampHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Model::class, HasTimestamps::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'freshtimestamp') {
            return null;
        }

        $date = ConfiguredDateClass::type($event->getSource()->getCodebase());

        return $date instanceof TNamedObject ? new Union([$date]) : Type::getMixed();
    }
}
