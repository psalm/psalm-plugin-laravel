<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Facades;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Facades\FacadeMethodHandler;

/**
 * Pure-logic tests for the `@see` parser and PHP-style relative name resolution used
 * by {@see FacadeMethodHandler}. No Psalm or Laravel boot — this isolates the parsing
 * and name-resolution rules from codebase state.
 */
#[CoversClass(FacadeMethodHandler::class)]
final class FacadeMethodHandlerTest extends TestCase
{
    #[Test]
    public function extracts_single_see_tag_fqcn(): void
    {
        $docblock = "/**\n * @see \\App\\Services\\LicenseService\n */";

        $this->assertSame(['\\App\\Services\\LicenseService'], FacadeMethodHandler::extractSeeCandidates($docblock));
    }

    #[Test]
    public function extracts_relative_see_tag(): void
    {
        $docblock = "/** @see LicenseService */";

        $this->assertSame(['LicenseService'], FacadeMethodHandler::extractSeeCandidates($docblock));
    }

    #[Test]
    public function strips_method_suffix_from_see_target(): void
    {
        $docblock = "/** @see \\App\\Foo::bar */";

        $this->assertSame(['\\App\\Foo'], FacadeMethodHandler::extractSeeCandidates($docblock));
    }

    #[Test]
    public function strips_property_suffix_from_see_target(): void
    {
        $docblock = "/** @see \\App\\Foo::\$baz */";

        $this->assertSame(['\\App\\Foo'], FacadeMethodHandler::extractSeeCandidates($docblock));
    }

    #[Test]
    public function ignores_url_see_tag(): void
    {
        $docblock = "/** @see https://laravel.com/docs */";

        $this->assertSame([], FacadeMethodHandler::extractSeeCandidates($docblock));
    }

    #[Test]
    public function ignores_inline_link_see_tag(): void
    {
        $docblock = "/** @see {@link \\App\\Foo} */";

        $this->assertSame([], FacadeMethodHandler::extractSeeCandidates($docblock));
    }

    #[Test]
    public function ignores_trailing_description(): void
    {
        // Only the first non-whitespace token after `@see` is captured, per PHPDoc convention.
        $docblock = "/** @see \\App\\Foo describes the underlying service */";

        $this->assertSame(['\\App\\Foo'], FacadeMethodHandler::extractSeeCandidates($docblock));
    }

    #[Test]
    public function collects_multiple_see_tags_in_order(): void
    {
        $docblock = <<<'DOC'
/**
 * @see \App\Foo
 * @see https://example.com
 * @see \App\Bar
 */
DOC;

        $this->assertSame(['\\App\\Foo', '\\App\\Bar'], FacadeMethodHandler::extractSeeCandidates($docblock));
    }

    #[Test]
    public function returns_empty_when_no_see_tag(): void
    {
        $docblock = "/** @method static bool isPlus() */";

        $this->assertSame([], FacadeMethodHandler::extractSeeCandidates($docblock));
    }

    #[Test]
    public function returns_empty_when_docblock_is_empty(): void
    {
        $this->assertSame([], FacadeMethodHandler::extractSeeCandidates(''));
    }

    #[Test]
    public function resolve_fqcn_returns_existing_class(): void
    {
        $this->assertSame(\DateTimeImmutable::class, FacadeMethodHandler::resolveRelativeName('\\' . \DateTimeImmutable::class, [], null));
    }

    #[Test]
    public function resolve_fqcn_returns_null_for_missing_class(): void
    {
        $this->assertNull(FacadeMethodHandler::resolveRelativeName('\\App\\NotAClass', [], null));
    }

    #[Test]
    public function resolve_short_name_via_use_import(): void
    {
        // `use DateTimeImmutable as StampedAt;` in namespace Foo — `@see StampedAt`
        // resolves through the use-import before namespace-relative resolution.
        $uses = ['stampedat' => \DateTimeImmutable::class];

        $this->assertSame(\DateTimeImmutable::class, FacadeMethodHandler::resolveRelativeName('StampedAt', $uses, 'Foo'));
    }

    #[Test]
    public function resolve_multi_segment_via_use_import(): void
    {
        // `use DateTime;` (aliased `datetime`); `@see DateTime\Foo` resolves to `DateTime\Foo`
        // by concatenating the use-mapped prefix with the remaining path.
        $uses = ['datetime' => \DateTime::class];

        // The resulting candidate `DateTime\NotAClass` does not exist, so null is returned —
        // this specifically exercises the "use-import prefix + rest" branch without requiring
        // a real multi-segment class to exist in the test environment.
        $this->assertNull(FacadeMethodHandler::resolveRelativeName('DateTime\\NotAClass', $uses, null));
    }

    #[Test]
    public function resolve_namespace_relative(): void
    {
        // No use-import matches; namespace concatenation falls back to the current namespace.
        $this->assertNull(FacadeMethodHandler::resolveRelativeName('NotAClass', [], 'App'));
    }

    #[Test]
    public function resolve_bare_global_class_without_leading_slash(): void
    {
        $this->assertSame(\DateTimeImmutable::class, FacadeMethodHandler::resolveRelativeName(\DateTimeImmutable::class, [], null));
    }

    #[Test]
    public function resolve_empty_name_returns_null(): void
    {
        $this->assertNull(FacadeMethodHandler::resolveRelativeName('', [], null));
    }

    #[Test]
    public function resolve_prefers_use_import_over_namespace(): void
    {
        // Given `namespace App; use DateTimeImmutable as Foo;`, `@see Foo` must resolve to
        // DateTimeImmutable (use-import), not App\Foo (namespace-relative).
        $uses = ['foo' => \DateTimeImmutable::class];

        $this->assertSame(\DateTimeImmutable::class, FacadeMethodHandler::resolveRelativeName('Foo', $uses, 'App'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function urlVariants(): iterable
    {
        yield 'https' => ['https://laravel.com'];
        yield 'http' => ['http://example.com/doc'];
        yield 'ftp' => ['ftp://example.com/'];
    }

    #[DataProvider('urlVariants')]
    #[Test]
    public function extractSeeCandidates_drops_various_url_schemes(string $url): void
    {
        $this->assertSame([], FacadeMethodHandler::extractSeeCandidates("/** @see {$url} */"));
    }
}
