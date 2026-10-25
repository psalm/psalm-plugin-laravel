<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class WithClosedShape
{
    /** @param array{count?: int} $options */
    public function optionalOtherKey(array $options): View
    {
        // A closed shape can only replace its own keys: `count`, not `name`, so the int stays what
        // the template receives and still reports.
        return view('greeting', ['name' => 456])->with($options);
    }

    /** @param array{name?: string} $options */
    public function optionalSameKey(array $options): View
    {
        // `name` may be replaced by the optional key, so the int passed above is not checked.
        return view('greeting', ['name' => 789])->with($options);
    }

    /** @param array{name: string}|array{other: int} $either */
    public function unionOfShapes(array $either): View
    {
        // Which shape arrives is unknown, so any earlier key may be replaced.
        return view('greeting', ['name' => 321])->with($either);
    }

    /** @param list<string> $args */
    public function spreadArguments(array $args): View
    {
        // A spread shifts every argument position: the call is not read at all.
        return view('greeting', ['name' => 654])->with(...$args);
    }
}
