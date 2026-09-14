<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Controllers;

final class ConcreteController extends \Illuminate\Routing\Controller
{
    public function __construct() {}

    public function show(): string
    {
        return self::class;
    }
}
