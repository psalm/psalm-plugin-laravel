<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\MarkerPrePass;

#[CoversClass(MarkerPrePass::class)]
final class MarkerPrePassTest extends TestCase
{
    #[Test]
    public function skips_verbatim_body_lines(): void
    {
        $skip = MarkerPrePass::computeSkipLines("@verbatim\n{{ raw }}\nstill raw\n@endverbatim\ndone\n");

        $this->assertArrayNotHasKey(1, $skip);
        $this->assertArrayHasKey(2, $skip);
        $this->assertArrayHasKey(3, $skip);
        $this->assertArrayHasKey(4, $skip);
        $this->assertArrayNotHasKey(5, $skip);
    }

    #[Test]
    public function skips_php_block_body_lines(): void
    {
        $skip = MarkerPrePass::computeSkipLines("@php\n\$x = 1;\n\$y = 2;\n@endphp\ndone\n");

        $this->assertArrayNotHasKey(1, $skip);
        $this->assertArrayHasKey(2, $skip);
        $this->assertArrayHasKey(3, $skip);
        $this->assertArrayHasKey(4, $skip);
        $this->assertArrayNotHasKey(5, $skip);
    }

    #[Test]
    public function skips_multiline_comment_body(): void
    {
        $skip = MarkerPrePass::computeSkipLines("{{--\nhidden\n--}}\nvisible\n");

        $this->assertArrayNotHasKey(1, $skip);
        $this->assertArrayHasKey(2, $skip);
        $this->assertArrayHasKey(3, $skip);
        $this->assertArrayNotHasKey(4, $skip);
    }

    #[Test]
    public function skips_multiline_directive_argument_body(): void
    {
        $skip = MarkerPrePass::computeSkipLines("@if(\n    \$a &&\n    \$b\n)\nyes\n@endif\n");

        $this->assertArrayNotHasKey(1, $skip);
        $this->assertArrayHasKey(2, $skip);
        $this->assertArrayHasKey(3, $skip);
        $this->assertArrayHasKey(4, $skip);
        $this->assertArrayNotHasKey(5, $skip);
    }

    #[Test]
    public function skips_multiline_component_tag_body(): void
    {
        // A marker between two attributes of a multi-line component tag defeats
        // ComponentTagCompiler's strict alternation match (see class docblock).
        $skip = MarkerPrePass::computeSkipLines("<x-alert\n    type=\"error\"\n    message=\"oops\"\n/>\ndone\n");

        $this->assertArrayNotHasKey(1, $skip);
        $this->assertArrayHasKey(2, $skip);
        $this->assertArrayHasKey(3, $skip);
        $this->assertArrayHasKey(4, $skip);
        $this->assertArrayNotHasKey(5, $skip);
    }

    #[Test]
    public function whitespace_only_lines_get_no_marker(): void
    {
        $marked = MarkerPrePass::inject("line one\n   \nline three\n");

        $this->assertStringNotContainsString('blade:2 */', $marked);
        $this->assertStringContainsString('blade:1 */', $marked);
        $this->assertStringContainsString('blade:3 */', $marked);
    }

    #[Test]
    public function appends_trailing_marker_for_extends_line(): void
    {
        $marked = MarkerPrePass::inject("@extends('layout')\n@section('content')\nhi\n@endsection\n");

        // Once for the opening-line marker, once more as the trailing footer marker.
        $this->assertSame(2, \substr_count($marked, '/* blade:1 */'));
    }

    #[Test]
    public function extends_line_returns_null_when_absent(): void
    {
        $this->assertNull(MarkerPrePass::extendsLine("hello\n"));
    }

    #[Test]
    public function extends_line_detects_source_line(): void
    {
        $this->assertSame(2, MarkerPrePass::extendsLine("line one\n@extends('layout')\n"));
    }
}
