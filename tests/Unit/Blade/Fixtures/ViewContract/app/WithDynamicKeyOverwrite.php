<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class WithDynamicKeyOverwrite
{
    public function dynamicKey(string $key): View
    {
        // `$key` may be 'name': the later `with()` then replaces the int, so the int is not what the
        // template receives and must not be type-checked.
        return view('greeting', ['name' => 123])->with($key, 'Ada');
    }

    /** @param array<string, string> $extra */
    public function unshapedArray(array $extra): View
    {
        // The array has no known keys, so it may replace `name` with a string.
        return view('greeting', ['name' => 123])->with($extra);
    }

    public function dynamicKeyRunsFirst(string $key): View
    {
        // The literal `with()` runs last and always wins `name`, so its int is what the template
        // receives: a dynamic key before it must not hide the mismatch.
        return view('greeting')->with($key, 'Ada')->with('name', 123);
    }
}
