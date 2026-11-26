<?php

declare(strict_types=1);

namespace ViewClassifyFixture;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

final class RawGeneric
{
    /** A raw collection reads as `Collection<array-key, mixed>`: Psalm calls it MixedArgumentTypeCoercion. */
    public function rawCollection(Collection $tags): View
    {
        return view('tags', ['tags' => $tags]);
    }

    /** The raw Eloquent collection fills its templates with their bounds, which no `Tag` satisfies. */
    public function rawEloquentCollection(EloquentCollection $tags): View
    {
        return view('tags', ['tags' => $tags]);
    }
}
