<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ShadowCompiler;
use Psalm\LaravelPlugin\Blade\ShadowManifest;
use Psalm\LaravelPlugin\Blade\ShadowResult;

/**
 * #1710: the reparse nonce must leave Psalm's parse of a broken shadow exactly as it was, and must
 * never eat template text. Parsed the way `StatementsProvider::parseStatements()` parses.
 */
#[CoversClass(ShadowManifest::class)]
final class ReparseNonceParseTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function brokenTemplates(): iterable
    {
        // Text after the closing tag would sit outside the bracketed namespace.
        yield 'bracketed namespace' => ["<?php namespace A { echo [1,,2]; } ?>\n"];

        // Author text that looks like a nonce, at the very end of the shadow.
        yield 'nonce lookalike at EOF' => ["@php echo [1,,2]; @endphp\n<?php echo 1; ?> // psalm-laravel-reparse:aa11"];
    }

    #[Test]
    #[DataProvider('brokenTemplates')]
    public function the_nonce_changes_neither_the_parse_errors_nor_the_template_text(string $template): void
    {
        $shadow = (new ShadowCompiler(new BladeCompiler(new Filesystem(), \sys_get_temp_dir())))->compile('/t.blade.php', $template);
        $this->assertInstanceOf(ShadowResult::class, $shadow);

        $before = $this->parseErrors($shadow->contents);
        $this->assertNotSame([], $before, 'the fixture must not parse');

        $once = ShadowManifest::withReparseNonce($shadow->contents, 'aa11');
        $twice = ShadowManifest::withReparseNonce($once, 'bb22');

        $this->assertNotSame($shadow->contents, $once);
        $this->assertNotSame($once, $twice, 'a new nonce must change the bytes');
        $this->assertSame($before, $this->parseErrors($once));
        $this->assertSame($before, $this->parseErrors($twice));
        $this->assertSame(\substr_count($shadow->contents, "\n"), \substr_count($twice, "\n"));
        $this->assertStringEndsWith(\substr($shadow->contents, -40), $twice, 'the template tail must survive');
        $this->assertSame(\substr_count($shadow->contents, 'psalm-laravel-reparse:') + 1, \substr_count($twice, 'psalm-laravel-reparse:'));
    }

    /** @return list<string> "line: message" */
    private function parseErrors(string $contents): array
    {
        $errors = new Collecting();
        (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4))->parse($contents, $errors);

        return \array_map(
            static fn(\PhpParser\Error $error): string => $error->getStartLine() . ': ' . $error->getRawMessage(),
            $errors->getErrors(),
        );
    }
}
