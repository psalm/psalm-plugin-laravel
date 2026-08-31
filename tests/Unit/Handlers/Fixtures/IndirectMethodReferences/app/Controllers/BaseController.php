<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Controllers;

use Illuminate\Routing\Controller;
use IndirectMethodReferencesFixture\Dependencies\InheritedActionDependency;

abstract class BaseController extends Controller
{
    public function __construct() {}

    public function inherited(InheritedActionDependency $dependency): void {}
}
