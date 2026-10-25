<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Illuminate\View\Compilers\BladeCompiler;
use PhpParser\Error as PhpParserError;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\CompileFailureReason;
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

    /** The template line the one shadow line containing $needle maps to. */
    private function bladeLineOf(ShadowResult $result, string $needle): int
    {
        $matching = \array_filter(
            \explode("\n", $result->contents),
            static fn(string $line): bool => \str_contains($line, $needle),
        );

        $this->assertCount(1, $matching, "expected exactly one shadow line containing {$needle}");
        $this->assertArrayHasKey(\array_key_first($matching) + 1, $result->lineMap, 'shadow line has no line-map entry');

        return $result->lineMap[\array_key_first($matching) + 1];
    }

    /** Psalm runs with E_ALL; PHPUnit's runtime reports less, which would let PHP errors pass. */
    private function compileReportingAll(ShadowCompiler $compiler, string $source): ShadowResult|CompileFailureReason
    {
        $previous = \error_reporting(\E_ALL);

        try {
            return $compiler->compile('view.blade.php', $source);
        } finally {
            \error_reporting($previous);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function lexicalBoundaryTemplates(): iterable
    {
        yield 'directive block comment' => ["@if(/* ) */\n true)\n{{ strlen([]) }}\n@endif\n"];
        yield 'raw string' => ["<?php\n\$tag = '?>';\nstrlen([]);\n?>\n"];
        yield 'escaped directive quote' => ["@if(str_contains('it\\'s)',\n 'x'))\n@php strlen([]); @endphp\n@endif\n"];
        // Bare-CR endings: a marker counted against `\n` alone lands inside the PHP body.
        yield 'bare CR php directive block' => ["@php\r\$x = 1;\rstrlen([]);\r@endphp\r{{ \$x }}\r"];
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
    public function directives_and_precompilers_see_the_template_path(): void
    {
        $bladeCompiler = new BladeCompiler(new Filesystem(), \sys_get_temp_dir());
        $seen = [];
        $bladeCompiler->directive('where', static function () use ($bladeCompiler, &$seen): string {
            $seen[] = $bladeCompiler->getPath();

            return '';
        });
        $bladeCompiler->precompiler(static function (string $value) use ($bladeCompiler, &$seen): string {
            $seen[] = $bladeCompiler->getPath();

            return $value;
        });

        $result = (new ShadowCompiler($bladeCompiler))->compile('/views/page.blade.php', "@where\n");

        $this->assertInstanceOf(ShadowResult::class, $result);
        $this->assertSame(['/views/page.blade.php', '/views/page.blade.php'], $seen);
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
    public function extends_footer_maps_to_the_extends_line(): void
    {
        // Acceptance (b): addFooters() appends the `@extends` footer at the very
        // end of the compiled output; it must still map to the @extends line.
        $result = $this->compiler->compile(
            'view.blade.php',
            "@extends('layout')\n@section('content')\nhi\n@endsection\n",
        );

        $this->assertInstanceOf(ShadowResult::class, $result);

        $lastShadowLine = \array_key_last($result->lineMap);
        $this->assertSame(1, $result->lineMap[$lastShadowLine]);
    }

    #[Test]
    public function extends_footer_marker_does_not_claim_the_last_line_of_a_source_without_trailing_newline(): void
    {
        $result = $this->compiler->compile('view.blade.php', "@extends('layout')\n{{ MissingClass::run() }}");

        $this->assertInstanceOf(ShadowResult::class, $result);
        $this->assertSame(2, $this->bladeLineOf($result, 'MissingClass::run()'));

        $lastShadowLine = \array_key_last($result->lineMap);
        $this->assertSame(1, $result->lineMap[$lastShadowLine]);
    }

    #[Test]
    public function each_statement_of_a_php_block_maps_to_its_own_line(): void
    {
        $result = $this->compiler->compile(
            'view.blade.php',
            "@php\n\$x = 1;\n\$y = 2;\n@endphp\ndone\n",
        );

        $this->assertInstanceOf(ShadowResult::class, $result);
        $this->assertSame(2, $this->bladeLineOf($result, '$x = 1;'));
        $this->assertSame(3, $this->bladeLineOf($result, '$y = 2;'));
        $this->assertNotEmpty($this->shadowLinesMappedTo($result, 5));
    }

    /**
     * Constructs whose interior a bare marker comment cannot enter: it would become part of the
     * quoted text (heredoc, nowdoc, string), part of the comment, or — past a `?>` — literal HTML.
     * Each interior line keeps inheriting the nearest preceding mapped line; the point here is that
     * the shadow still parses and the quoted bytes still say what the author wrote.
     *
     * @return iterable<string, array{string, string}> source, text the shadow must still contain
     */
    public static function unmarkableInteriors(): iterable
    {
        yield 'heredoc' => ["<?php\n\$a = <<<EOT\n  body line\nEOT;\n\$b = 1;\n?>\n", "  body line\nEOT;"];
        yield 'multi-line string' => ["<?php\n\$a = 'one\ntwo';\n\$b = 1;\n?>\n", "'one\ntwo';"];
        yield 'block comment' => ["<?php\n/* one\n   two */\n\$b = 1;\n?>\n", "/* one\n   two */"];
        yield 'multi-line call' => ["<?php\nstrlen(\n    'x',\n);\n?>\n", "'x',"];

        // The block's opener has to be rewritten to `<?php` before its body can be tokenized, and
        // `<?=` is the one opener a body can follow with no separator at all. Glued straight on,
        // `<?php<<<'TXT'` is not an open tag but inline HTML, and the `<?php` INSIDE the nowdoc
        // then reads as the one that opens PHP mode: the body's own lines are judged markable and
        // a marker lands in front of the closing delimiter, which ends the nowdoc nowhere.
        yield 'short echo opening a nowdoc' => ["<?=<<<'TXT'\n<?php\necho \"sample\";\nTXT ?>\n", "<?php\necho \"sample\";\nTXT"];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('unmarkableInteriors')]
    public function a_php_block_stays_parseable_and_verbatim(string $source, string $preserved): void
    {
        $result = $this->compiler->compile('view.blade.php', $source);

        $this->assertInstanceOf(ShadowResult::class, $result);
        $this->assertStringContainsString($preserved, $result->contents);

        try {
            $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($result->contents);
        } catch (PhpParserError $phpParserError) {
            $this->fail('shadow is not parseable PHP: ' . $phpParserError->getMessage());
        }

        $this->assertNotNull($stmts);
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

        $this->assertInstanceOf(CompileFailureReason::class, $result);
        $this->assertSame('view.blade.php', $result->templatePath);
        $this->assertSame('Target [Illuminate\\Contracts\\View\\Factory] is not instantiable.', $result->message);
    }

    #[Test]
    public function a_compile_error_is_one_line_of_the_exception_message_without_its_class(): void
    {
        $blade = new BladeCompiler(new Filesystem(), \sys_get_temp_dir());
        $blade->directive('explode', static function (): never {
            throw new \LogicException("the explode directive\n    needs a request\n");
        });

        $result = (new ShadowCompiler($blade))->compile('view.blade.php', "@explode\n");

        $this->assertInstanceOf(CompileFailureReason::class, $result);
        $this->assertSame('the explode directive needs a request', $result->message);
    }

    #[Test]
    public function a_php_error_in_an_argumentless_directive_asks_for_arguments(): void
    {
        $result = $this->compileReportingAll($this->compiler, "<p>\n  Wrap it in a @component block.\n</p>\n");

        $this->assertInstanceOf(CompileFailureReason::class, $result);
        $this->assertSame('@component needs arguments; write @@component to print it as text', $result->message);
    }

    #[Test]
    public function an_argumentless_directive_whose_compile_method_is_typed_asks_for_arguments(): void
    {
        // compileJs(string) throws a TypeError before any PHP error is raised. Production php.ini
        // drops exception arguments from traces, which must not cost the advice.
        foreach (['0', '1'] as $ignoreArgs) {
            $previous = \ini_set('zend.exception_ignore_args', $ignoreArgs);

            try {
                $result = $this->compiler->compile('view.blade.php', "Use @js here\n");
            } finally {
                \ini_set('zend.exception_ignore_args', (string) $previous);
            }

            $this->assertInstanceOf(CompileFailureReason::class, $result);
            $this->assertSame('@js needs arguments; write @@js to print it as text', $result->message, "exception_ignore_args={$ignoreArgs}");
        }
    }

    #[Test]
    public function a_custom_directive_never_gets_the_arguments_advice(): void
    {
        // callCustomDirective() passes '' for a bare directive, so the null is the closure's own. Str is
        // not strict, so the null raises the deprecation rather than a TypeError.
        $blade = new BladeCompiler(new Filesystem(), \sys_get_temp_dir());
        $blade->directive('analytics', static fn(): string => Str::lower(null));
        // Overrides a built-in directive whose compile method does require an argument.
        $blade->directive('section', static fn(): string => Str::lower(null));

        foreach (['analytics', 'section'] as $directive) {
            $result = $this->compileReportingAll(new ShadowCompiler($blade), "Track it with @{$directive} today\n");

            $this->assertInstanceOf(CompileFailureReason::class, $result);
            $this->assertSame(
                "@{$directive}: mb_strtolower(): Passing null to parameter #1 (\$string) of type string is deprecated",
                $result->message,
            );
        }
    }

    #[Test]
    public function a_directive_catching_the_runtime_exception_psalm_throws_still_compiles(): void
    {
        $blade = new BladeCompiler(new Filesystem(), \sys_get_temp_dir());
        $blade->directive('guarded', static function (): string {
            try {
                \trigger_error('optional feature unavailable', \E_USER_WARNING);
            } catch (\RuntimeException $runtimeException) {
                return "<?php /* {$runtimeException->getCode()} */ ?>";
            }

            return '';
        });

        $result = $this->compileReportingAll(new ShadowCompiler($blade), "@guarded\n");

        $this->assertInstanceOf(ShadowResult::class, $result);
        $this->assertStringContainsString('/* ' . \E_USER_WARNING . ' */', $result->contents);
    }

    #[Test]
    public function a_php_error_in_a_directive_with_arguments_names_the_directive(): void
    {
        $blade = new BladeCompiler(new Filesystem(), \sys_get_temp_dir());
        $blade->directive('warn', static function (string $expression): string {
            \trigger_error("cannot compile {$expression}", \E_USER_WARNING);

            return '';
        });

        $result = $this->compileReportingAll(new ShadowCompiler($blade), "@warn(\$user)\n");

        $this->assertInstanceOf(CompileFailureReason::class, $result);
        $this->assertSame('@warn: cannot compile $user', $result->message);
    }

    #[Test]
    public function the_previous_error_handler_is_restored_after_a_failed_compile(): void
    {
        $sentinel = static fn(): bool => false;
        \set_error_handler($sentinel);

        try {
            $result = $this->compiler->compile('view.blade.php', "<x-alert/>\n");
            $current = \set_error_handler(static fn(): bool => false);
            \restore_error_handler();
        } finally {
            \restore_error_handler();
        }

        $this->assertInstanceOf(CompileFailureReason::class, $result);
        $this->assertSame($sentinel, $current);
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

    /**
     * #1543: a REAL `<x-...>` tag's bookkeeping originates from ONE Blade source line, so
     * {@see \Psalm\LaravelPlugin\Blade\MarkerPrePass} (which runs BEFORE `compileString()`, on the
     * raw source) never inserts a marker comment between its compiled sub-statements — confirmed
     * against a real compile of `nested-attributes.blade.php` (no fixture container here, so this
     * writes the four statements as raw pass-through PHP instead). Four hand-written statements
     * across four SOURCE lines, by contrast, each get their own marker, which is exactly why
     * {@see \Psalm\LaravelPlugin\Blade\AttributesRestoreReassert}'s pattern declines to match this
     * shape: it is anchored to the compiler's actual, marker-free output, not to text that merely
     * resembles it.
     *
     * A container-less `BladeCompiler` cannot compile a real `<x-...>` tag (see
     * `component_tag_yields_a_compile_error` above), so the `isComponentView()` gate itself — a
     * plain page that DOES carry a `<x-...>` tag, where a gate-less injection would have a real
     * restore block to match — is pinned in
     * `BladeIssueRemapTest::a_plain_caller_with_a_component_tag_gets_no_attributes_reassert`
     * instead, against a real compile through the fixture's booted application.
     */
    #[Test]
    public function hand_written_lines_resembling_the_restore_are_not_reasserted(): void
    {
        $source = "{{ \$attributes }}\n"
            . "<?php if (isset(\$__attributesOriginalabc)): ?>\n"
            . "<?php \$attributes = \$__attributesOriginalabc; ?>\n"
            . "<?php unset(\$__attributesOriginalabc); ?>\n"
            . "<?php endif; ?>\n";

        $result = $this->compiler->compile('view.blade.php', $source);

        $this->assertInstanceOf(ShadowResult::class, $result);
        // The prelude itself declares $attributes non-null (a bare mention, no @props) — that
        // docblock is expected. Only a reassert glued onto `endif;` would be the bug.
        $this->assertStringNotContainsString('endif; /** @var', $result->contents);
    }
}
