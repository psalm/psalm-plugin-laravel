<?php

declare(strict_types=1);

namespace BladeViewDataTaintFixture\Probe;

use Illuminate\Http\Request;

/**
 * A namespaced function that merely shares the Laravel helper's name.
 *
 * @psalm-suppress MissingPureAnnotation
 */
function view(string $name, array $data): string
{
    return $name . \count($data);
}

/**
 * @psalm-suppress UnusedClass
 */
final class UsesNamespacedFunction
{
    public function run(Request $request): string
    {
        return view('probe-fn', ['q' => $request->input('q')]);
    }
}
