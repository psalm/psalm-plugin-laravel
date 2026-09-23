<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\MarkerComment;
use Psalm\LaravelPlugin\Blade\ShadowEntry;
use Psalm\LaravelPlugin\Blade\ShadowResult;
use Psalm\LaravelPlugin\Blade\ShadowTarget;
use Psalm\LaravelPlugin\Blade\TemplateSnippetMatcher;

#[CoversClass(ShadowTarget::class)]
final class ShadowTargetTest extends TestCase
{
    private const TEMPLATE = '/app/resources/views/edit.blade.php';

    private const SOURCE = "@php\nstrlen(\n  'a',\n  'b',\n);\n@endphp\n";

    private function target(string $templateSource, string $markerPrefix): ShadowTarget
    {
        return new ShadowTarget(
            new ShadowEntry(self::TEMPLATE, [1 => 0, 2 => 2], []),
            $templateSource,
            'resources/views/edit.blade.php',
            false,
            $markerPrefix,
        );
    }

    /**
     * The prefix is a salted hash of the template, and {@see \Psalm\LaravelPlugin\Blade\ShadowRegistry}
     * reads the template again during relocation, so the bytes on disk then need not be the bytes that
     * compiled. A prefix re-derived from the later bytes matches nothing in the shadow, the marker
     * strip silently no-ops, and the arity gate reads the author's own call as compiler-generated and
     * drops a real finding. Before #1544 a no-op strip could only cost noise; now it costs findings, so
     * the compile-time prefix travels with the target instead of being re-derived from live bytes.
     */
    #[Test]
    public function the_compile_time_prefix_survives_a_template_that_changed_afterwards(): void
    {
        $compiler = new BladeCompiler(new Filesystem(), \sys_get_temp_dir());
        $result = (new \Psalm\LaravelPlugin\Blade\ShadowCompiler($compiler))->compile(self::TEMPLATE, self::SOURCE);

        $this->assertInstanceOf(ShadowResult::class, $result);

        $compiled = MarkerComment::prefixFor(self::SOURCE);
        $changed = self::SOURCE . "{{-- a footer appended after the compile --}}\n";

        $this->assertNotSame($compiled, MarkerComment::prefixFor($changed), 'the mutation must move the salt, or this test proves nothing');

        $target = $this->target($changed, $compiled);

        $this->assertSame($compiled, $target->markerPrefix());

        // The consequence that matters: a snippet cut out of the real shadow still compares clean
        // against the template it came from.
        $snippet = "strlen(\n  /* {$compiled}3 */ 'a',\n  /* {$compiled}4 */ 'b',\n)";
        $this->assertTrue(TemplateSnippetMatcher::occursIn($snippet, $changed, $target->markerPrefix()));
    }

    #[Test]
    public function an_unmapped_shadow_line_has_no_template_line(): void
    {
        $target = $this->target(self::SOURCE, MarkerComment::prefixFor(self::SOURCE));

        $this->assertSame(0, $target->templateLineFor(1));
        $this->assertSame(2, $target->templateLineFor(2));
        $this->assertSame(0, $target->templateLineFor(99));
    }
}
