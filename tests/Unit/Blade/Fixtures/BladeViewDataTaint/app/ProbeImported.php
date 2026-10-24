<?php

declare(strict_types=1);

namespace BladeViewDataTaintFixture\ProbeImported;

use function BladeViewDataTaintFixture\Probe\view;

use Illuminate\Http\Request;

// Psalm sees a project function only through the requiring file's own includes.
require_once __DIR__ . '/ProbeFunction.php';

/**
 * @psalm-suppress UnusedClass
 */
final class UsesImportedFunction
{
    public function run(Request $request): string
    {
        return view('probe-imported', ['q' => $request->input('q')]);
    }
}
