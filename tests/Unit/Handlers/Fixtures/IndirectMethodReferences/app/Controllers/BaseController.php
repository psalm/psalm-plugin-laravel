<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Controllers;

use Illuminate\Routing\Controller;

abstract class BaseController extends Controller
{
    public function __construct() {}

    public function inherited(): void {}
}
