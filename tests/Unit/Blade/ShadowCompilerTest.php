<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use PhpParser\Error as PhpParserError;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\BladeCompileError;
use Psalm\LaravelPlugin\Blade\MarkerPrePass;
use Psalm\LaravelPlugin\Blade\ShadowCompiler;
use Psalm\LaravelPlugin\Blade\ShadowResult;

#[CoversClass(ShadowCompiler::class)]
final class ShadowCompilerTest extends TestCase
{
    private ShadowCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new ShadowCompiler(new BladeCompiler(new Filesystem(), \sys_get_temp_dir()));
    }

    /** @return array<int, int> shadow line numbers (values) that map to $bladeLine */
    private function shadowLinesMappedTo(ShadowResult $result, int $bladeLine): array
    {
        return \array_keys(\array_filter($result->lineMap, static fn(int $v): bool => $v === $bladeLine));
    }

    /** @return iterable<string, array{string}> */
    public static function lexicalBoundaryTemplates(): iterable
    {
        yield 'directive block comment' => ["@if(/* ) */\n true)\n{{ strlen([]) }}\n@endif\n"];
        yield 'directive line comment' => ["@if(// )\n true)\n{{ strlen([]) }}\n@endif\n"];
        yield 'directive hash comment' => ["@if(# )\n true)\n{{ strlen([]) }}\n@endif\n"];
        yield 'switch argument comment' => ["@switch(/* ) @case(1) */\n 1)\n@case(1)\n{{ strlen([]) }}\n@break\n@endswitch\n"];
        yield 'raw string' => ["<?php\n\$tag = '?>';\nstrlen([]);\n?>\n"];
        yield 'raw comment' => ["<?php\n/* ?> */\nstrlen([]);\n?>\n"];
        yield 'escaped directive quote' => ["@if(str_contains('it\\'s)',\n 'x'))\n@php strlen([]); @endphp\n@endif\n"];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('lexicalBoundaryTemplates')]
    public function lexical_boundaries_preserve_valid_php(string $source): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $plain = (new BladeCompiler(new Filesystem(), \sys_get_temp_dir()))->compileString($source);
        $this->assertNotNull($parser->parse($plain));
        $result = $this->compiler->compile('view.blade.php', $source);
        $this->assertInstanceOf(ShadowResult::class, $result);
        $this->assertNotNull($parser->parse($result->contents));
        $this->assertStringContainsString('strlen([])', $result->contents);
    }

    #[Test]
    public function author_marker_text_cannot_change_the_line_map(): void
    {
        $source = "{!! '/* blade:999 */' . request()->input('q') !!}\n<?php /* blade:1 */ strlen([]); ?>\n/* blade:999 */\n";
        $result = $this->compiler->compile('view.blade.php', $source);

        $this->assertInstanceOf(ShadowResult::class, $result);
        $this->assertNotContains(999, $result->lineMap);
        foreach (\explode("\n", $result->contents) as $index => $line) {
            if (\str_contains($line, 'strlen([])')) {
                $this->assertSame(2, $result->lineMap[$index + 1]);
            }
        }
    }

    #[Test]
    public function forged_marker_comments_do_not_redirect_suppressions(): void
    {
        $source = "{{-- @psalm-suppress InvalidArgument --}}\n<?php /* blade:deadbeef */ strlen([]); ?>\n<?php strlen([1]); ?>\n";
        $result = $this->compiler->compile('view.blade.php', $source);

        $this->assertInstanceOf(ShadowResult::class, $result);
        $this->assertSame([2 => ['InvalidArgument']], $result->suppressions);
        $this->assertStringContainsString('<?php /** @psalm-suppress InvalidArgument */ /* blade:deadbeef */ strlen([]); ?>', $result->contents);
        $this->assertStringContainsString('<?php strlen([1]); ?>', $result->contents);
    }

    #[Test]
    public function compiles_a_plain_echo(): void
    {
        $result = $this->compiler->compile('view.blade.php', "Hello {{ \$name }}\n");

        $this->assertInstanceOf(ShadowResult::class, $result);
        $this->assertStringContainsString('echo e($name)', $result->contents);
    }

    #[Test]
    public function compiles_a_raw_echo(): void
    {
        $result = $this->compiler->compile('view.blade.php', "Hello {!! \$name !!}\n");

        $this->assertInstanceOf(ShadowResult::class, $result);
        $this->assertStringContainsString('echo $name;', $result->contents);
    }

    #[Test]
    public function echo_at_end_of_line_doubles_the_trailing_newline_but_both_map_to_the_same_source_line(): void
    {
        // Acceptance (a): Blade's echo compiler doubles the trailing newline;
        // both resulting shadow lines must still map back to source line 1.
        $result = $this->compiler->compile('view.blade.php', "Hello {{ \$name }}\n");

        $this->assertCount(3, $this->shadowLinesMappedTo($result, 1));
    }

    #[Test]
    public function compiles_foreach(): void
    {
        $result = $this->compiler->compile('view.blade.php', "@foreach(\$items as \$item)\n{{ \$item }}\n@endforeach\n");

        $this->assertInstanceOf(ShadowResult::class, $result);
        $this->assertStringContainsString('foreach($__currentLoopData as $item)', $result->contents);
        $this->assertNotEmpty($this->shadowLinesMappedTo($result, 2));
    }

    #[Test]
    public function compiles_if(): void
    {
        $result = $this->compiler->compile('view.blade.php', "@if(\$cond)\nyes\n@endif\n");

        $this->assertInstanceOf(ShadowResult::class, $result);
        $this->assertStringContainsString('if($cond):', $result->contents);
    }

    #[Test]
    public function compiles_include(): void
    {
        $result = $this->compiler->compile('view.blade.php', "before\n@include('partial')\nafter\n");

        $this->assertInstanceOf(ShadowResult::class, $result);
        $this->assertStringContainsString("\$__env->make('partial'", $result->contents);
    }

    #[Test]
    public function extends_footer_maps_to_the_extends_line(): void
    {
        // Acceptance (b): addFooters() appends the `@extends` footer at the very
        // end of the compiled output; it must still map to the @extends line.
        $result = $this->compiler->compile(
            'view.blade.php',
            "@extends('layout')\n@section('content')\nhi\n@endsection\n",
        );

        $this->assertInstanceOf(ShadowResult::class, $result);
        $this->assertSame(1, $result->extendsLine);

        $lastShadowLine = \array_key_last($result->lineMap);
        $this->assertSame(1, $result->lineMap[$lastShadowLine]);
    }

    #[Test]
    public function extends_line_is_null_without_extends(): void
    {
        $result = $this->compiler->compile('view.blade.php', "hello\n");

        $this->assertInstanceOf(ShadowResult::class, $result);
        $this->assertNull($result->extendsLine);
    }

    #[Test]
    public function compiles_props_with_named_and_bare_entries(): void
    {
        $result = $this->compiler->compile(
            'view.blade.php',
            "@props(['title' => 'Default', 'count'])\n<div>{{ \$title }}</div>\n",
        );

        $this->assertInstanceOf(ShadowResult::class, $result);
        $this->assertStringContainsString('extractPropNames', $result->contents);
        // The @props preamble collapses to the opening line; the div is its own line.
        $this->assertNotEmpty($this->shadowLinesMappedTo($result, 1));
        $this->assertNotEmpty($this->shadowLinesMappedTo($result, 2));
    }

    #[Test]
    public function php_block_body_maps_to_its_opening_line(): void
    {
        $result = $this->compiler->compile(
            'view.blade.php',
            "@php\n\$x = 1;\n\$y = 2;\n@endphp\ndone\n",
        );

        $this->assertInstanceOf(ShadowResult::class, $result);
        // Every line of the @php body (opening line + 2 statements + @endphp) maps to line 1.
        $this->assertGreaterThanOrEqual(4, \count($this->shadowLinesMappedTo($result, 1)));
        $this->assertNotEmpty($this->shadowLinesMappedTo($result, 5));
    }

    #[Test]
    public function compiles_verbatim(): void
    {
        $result = $this->compiler->compile(
            'view.blade.php',
            "@verbatim\n{{ raw }}\nstill raw\n@endverbatim\ndone\n",
        );

        $this->assertInstanceOf(ShadowResult::class, $result);
        $this->assertStringContainsString('{{ raw }}', $result->contents);
        $this->assertStringContainsString('still raw', $result->contents);
    }

    #[Test]
    public function undeclared_variable_gets_var_mixed_in_the_prelude(): void
    {
        $result = $this->compiler->compile('view.blade.php', "Hello {{ \$foo }}\n");

        $this->assertInstanceOf(ShadowResult::class, $result);
        $this->assertStringContainsString('@var mixed $foo */', $result->contents);
    }

    #[Test]
    public function contract_vars_are_typed_in_the_prelude_instead_of_mixed(): void
    {
        $result = $this->compiler->compile(
            'view.blade.php',
            "Hello {{ \$user }}\n",
            ['user' => '\App\Models\User'],
        );

        $this->assertInstanceOf(ShadowResult::class, $result);
        $this->assertStringContainsString('@var \App\Models\User $user */', $result->contents);
        $this->assertStringNotContainsString('@var mixed $user', $result->contents);
    }

    #[Test]
    public function suppress_comment_lands_inside_the_next_statements_php_block(): void
    {
        $result = $this->compiler->compile(
            'view.blade.php',
            "{{-- @psalm-suppress UndefinedVariable --}}\n{{ \$foo }}\n",
        );

        $this->assertInstanceOf(ShadowResult::class, $result);
        $this->assertStringContainsString('@psalm-suppress UndefinedVariable', $result->contents);
        $this->assertStringContainsString('<?php /** @psalm-suppress UndefinedVariable */ echo e($foo)', $result->contents);
        $this->assertLessThan(
            \strpos($result->contents, 'echo e($foo)'),
            \strpos($result->contents, '@psalm-suppress UndefinedVariable'),
        );
    }

    #[Test]
    public function component_tag_yields_a_compile_error(): void
    {
        // A bare BladeCompiler has no container to resolve the view factory
        // component tags need — this IS the compile-error case, not a bug.
        $result = $this->compiler->compile('view.blade.php', "<x-alert/>\n");

        $this->assertInstanceOf(BladeCompileError::class, $result);
        $this->assertSame('view.blade.php', $result->templatePath);
        $this->assertStringContainsString('BindingResolutionException', $result->message);
    }

    #[Test]
    public function multiline_component_tag_is_still_recognized_and_yields_a_compile_error(): void
    {
        // The naive failure mode: a marker landing between the tag's attribute
        // lines would defeat ComponentTagCompiler's match, leaving the tag as
        // literal text instead — a SILENT no-op, not this compile error.
        $result = $this->compiler->compile('view.blade.php', "<x-alert\n    type=\"error\"\n/>\n");

        $this->assertInstanceOf(BladeCompileError::class, $result);
        $this->assertStringContainsString('BindingResolutionException', $result->message);
    }

    #[Test]
    public function marker_pre_pass_never_splits_a_multiline_component_tag(): void
    {
        $marked = MarkerPrePass::inject("<x-alert\n    type=\"error\"\n/>\n");

        $this->assertStringContainsString('blade:1 */', $marked);
        $this->assertStringNotContainsString('blade:2 */', $marked);
        $this->assertStringNotContainsString('blade:3 */', $marked);
    }

    #[Test]
    public function multiline_raw_php_block_yields_a_parseable_shadow(): void
    {
        // A marker injected mid-block (while PHP mode is already open) breaks the shadow's
        // syntax outright; this must lint clean, matching Laravel's own compiler.
        $result = $this->compiler->compile('view.blade.php', "<?php\n\$x = 1;\n?>\nhello {{ \$x }}\n");

        $this->assertInstanceOf(ShadowResult::class, $result);

        try {
            $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($result->contents);
        } catch (PhpParserError $phpParserError) {
            $this->fail('shadow is not parseable PHP: ' . $phpParserError->getMessage());
        }

        $this->assertNotNull($stmts);
    }

    #[Test]
    public function switch_yields_a_parseable_shadow(): void
    {
        // Blade's compileSwitch() opens PHP mode for the switch statement without closing it;
        // PHP mode stays open until the first @case closes it, since compileCase() relies on
        // the switch's still-open tag instead of opening its own. A marker injected anywhere in
        // between lands inside open PHP code and breaks the shadow's syntax outright, exactly
        // like the raw-php-block case above.
        $source = "@switch(\$type)\n\n{{-- comment --}}\n@case('a')\nfoo\n@break\n@endswitch\n";
        $result = $this->compiler->compile('view.blade.php', $source);

        $this->assertInstanceOf(ShadowResult::class, $result);

        try {
            $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($result->contents);
        } catch (PhpParserError $phpParserError) {
            $this->fail('shadow is not parseable PHP: ' . $phpParserError->getMessage());
        }

        $this->assertNotNull($stmts);

        // Gated lines (the blank line, the comment, and the @case line itself) have no marker of
        // their own; LineMapBuilder's $last carry-forward makes them fall back to the nearest
        // preceding marker, which is the @switch line. An exact count of 4 pins that the fallback
        // spans blade lines 1-4 (switch through the @case line itself) and no further; line 4
        // never appearing as a mapped VALUE confirms the @case line's own marker was suppressed,
        // not merely coincident with the switch line's.
        $this->assertCount(4, $this->shadowLinesMappedTo($result, 1));
        $this->assertNotContains(4, $result->lineMap);

        // Once the @case line closes PHP mode, the body line right after it maps to its own line.
        $this->assertNotEmpty($this->shadowLinesMappedTo($result, 5));
    }
}
