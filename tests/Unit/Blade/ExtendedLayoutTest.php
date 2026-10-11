<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ExtendedLayout;

#[CoversClass(ExtendedLayout::class)]
final class ExtendedLayoutTest extends TestCase
{
    /** @return iterable<string, array{string, string|false|null}> */
    public static function sources(): iterable
    {
        yield 'none' => ["hello\n", null];
        yield 'single quotes' => ["@extends('layouts.app')\n", 'layouts.app'];
        yield 'double quotes, padded' => ["@extends ( \"layouts.app\" )\n", 'layouts.app'];
        yield 'inside a Blade comment' => ["{{-- @extends('old') --}}\n@extends('real')\n", 'real'];
        yield 'escaped' => ["@@extends('layouts.app')\n", null];
        yield 'inside a word' => ["mail@extends('layouts.app')\n", null];
        yield 'variable' => ["@extends(\$layout)\n", false];
        yield 'interpolated' => ["@extends(\"layouts.{\$theme}\")\n", false];
        yield 'concatenated' => ["@extends('layouts.' . \$theme)\n", false];
        yield 'with data' => ["@extends('layouts.app', ['title' => 'x'])\n", false];
        yield 'namespaced' => ["@extends('pkg::layout')\n", false];
        yield 'extendsFirst' => ["@extendsFirst(['a', 'b'])\n", false];
        yield 'uppercase, as Blade matches directives' => ["@EXTENDS('layouts.app')\n", 'layouts.app'];
        yield 'mixed case' => ["@Extends('layouts.app')\n", 'layouts.app'];
        yield 'uppercase extendsFirst' => ["@EXTENDSFIRST(['a', 'b'])\n", false];
        yield 'slash-separated, normalized like ViewName::normalize()' => ["@extends('layouts/app')\n", 'layouts.app'];
        yield 'two live' => ["@extends('a')\n@extends('b')\n", false];
        yield "inside another directive's arguments" => ["@section('help', \"@extends('layouts.app')\")\n", null];
        yield 'inside an inline @php string' => ["@php(\$x = \"@extends('layouts.app')\")\n", null];
        yield "inside an escaped directive's arguments" => ["@@section(\"@extends('layouts.app')\")\n", null];
        yield 'real one after a nested one' => ["@section('help', \"@extends('old')\")\n@extends('layouts.app')\n", 'layouts.app'];
        yield 'inside a @php block' => ["@php \$x = \"@extends('layouts.app')\"; @endphp\n", null];
        yield 'inside a raw PHP block' => ["<?php \$x = \"@extends('layouts.app')\"; ?>\n", null];
        yield 'inside @verbatim' => ["@verbatim @extends('layouts.app') @endverbatim\n", null];
        yield 'a removed comment makes an escape' => ["@{{-- gap --}}@extends('layouts.app')\n", null];
        yield 'a removed comment joins the directive' => ["@extends{{-- gap --}}('layouts.app')\n", 'layouts.app'];
        yield "swallowed by a directive's paren balancing" => ["@if(foo(\"(\") @extends('b'))\n", null];
        yield 'no arguments' => ["@extends\n", false];
    }

    #[Test]
    #[DataProvider('sources')]
    public function a_single_literal_view_name_or_unprovable(string $source, string|false|null $expected): void
    {
        $this->assertSame($expected, ExtendedLayout::of($source));
    }
}
