<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Controllers;

use Illuminate\Routing\Controller;
use IndirectMethodReferencesFixture\Dependencies\InvokeDependency;

final class InvokableController extends Controller
{
    public function __invoke(InvokeDependency $dependency): void {}
}
