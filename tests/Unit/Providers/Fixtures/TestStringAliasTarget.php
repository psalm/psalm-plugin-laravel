<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Providers\Fixtures;

/**
 * Concrete class that {@see TestStringAliasServiceProvider} binds under a
 * string key. Existence is enough — the registrar test only asserts that
 * the string key resolves to an instance of this class.
 */
final class TestStringAliasTarget {}
