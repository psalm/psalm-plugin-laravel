<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\Annotate\AnnotationCollector;
use Psalm\LaravelPlugin\Blade\Annotate\AnnotationWriter;
use Psalm\LaravelPlugin\Blade\Annotate\TemplateAnnotator;

/**
 * End-to-end proof that the annotate pass declares a template's variables from the types the
 * `view()` call sites pass. A real `vendor/bin/psalm` run is the only way to pin it: the producer
 * types exist only during an analysis, which is the whole reason the codemod is driven by one.
 *
 * Every case but one reads the one memoized annotate run ({@see RunsAnnotateFixture}), the exception
 * being an ordinary re-run over the annotated templates; the splice mechanics
 * themselves are unit-tested in TemplateAnnotatorTest and AnnotationWriterTest, so what is pinned
 * here is the producer types a real analysis feeds them. The no-write modes (dry run, a control file
 * this CLI did not write, a project with Blade off) live in {@see AnnotateSafetyTest}.
 *
 * The command half (argument forwarding, output handling) is covered by
 * {@see \Tests\Psalm\LaravelPlugin\Unit\Cli\AnnotateCommandTest}; what it ultimately does is set
 * the environment variable this test sets by hand, against a fixture that is not a project root
 * and so has no `vendor/bin/psalm` of its own.
 */
#[CoversClass(AnnotationCollector::class)]
#[CoversClass(AnnotationWriter::class)]
#[CoversClass(TemplateAnnotator::class)]
#[Group('subprocess')]
final class AnnotateEndToEndTest extends TestCase
{
    use RunsAnnotateFixture;

    /** @return iterable<string, array{string, string}> template name => the template after the pass */
    public static function declaredTemplates(): iterable
    {
        $declare = static fn(string $body, string $template): string => "<?php\n/**\n{$body} */\n?>\n" . $template;

        yield 'the type every call site agreed on' => ['home', $declare(
            " * @var \\AnnotateFixture\\Post \$post\n * @var string \$title\n",
            self::TEMPLATES['home'],
        )];
        yield 'a namespaced view from its qualified call sites' => ['pkg:widget', $declare(
            " * @var string \$title\n",
            self::PKG_WIDGET,
        )];
        yield 'an existing one-line docblock, its declaration untouched' => [
            'declared',
            "<?php /**\n * @var string \$title\n * @var string \$extra\n */ ?>\n<h1>{{ \$title }}</h1>\n<p>{{ \$extra }}</p>\n",
        ];
        yield 'the header docblock of a template that has one' => [
            'headed',
            "<?php\n\ndeclare(strict_types=1);\n\n/**\n * Header.\n * @var string \$title\n */\n?>\n<h1>{{ \$title }}</h1>\n",
        ];
        yield 'a block after the declare, which stays the first statement' => [
            'strict',
            "<?php declare(strict_types=1); ?>\n" . $declare(" * @var string \$title\n", "<h1>{{ \$title }}</h1>\n"),
        ];
        yield 'a key a closed call site omits, nullable with an equals-null tail' => ['note', $declare(
            " * @var string|null \$note = null\n",
            self::TEMPLATES['note'],
        )];
        // A declared `$item` makes every correct call site report MissingViewData: the alias is the
        // template's own, not something the caller is expected to pass.
        yield 'never a loop alias the template binds itself' => ['loop', $declare(
            " * @var list<string> \$items\n",
            self::TEMPLATES['loop'],
        )];
        yield 'an unbounded int range printed as int' => ['statics', $declare(
            " * @var array{total: int} \$stats\n",
            self::TEMPLATES['statics'],
        )];
        // Nullability, sibling classes up to three, `true`/`false`, and one class with different
        // generic params merge; a fourth class and two scalar kinds are listed.
        yield 'call sites that differ only in ways one declaration covers, merged' => ['merged', $declare(
            " * @var \\AnnotateFixture\\Post|null \$author\n"
            . " * @var bool \$flag\n"
            . " * @var \\AnnotateFixture\\Post|\\AnnotateFixture\\Video \$media\n"
            . " * @var \\Illuminate\\Support\\Collection<int, \\AnnotateFixture\\Post>|\\Illuminate\\Support\\Collection<int, \\AnnotateFixture\\Video> \$posts\n"
            . " * @var \\AnnotateFixture\\Panel|\\AnnotateFixture\\Post|\\AnnotateFixture\\Video \$trio\n",
            self::TEMPLATES['merged'],
        )];
        // `[]` and a fixed list sit inside `list<string>`.
        yield 'arrays merged into the one call-site type that covers them' => ['arrays', $declare(
            " * @var list<string> \$labels\n",
            self::TEMPLATES['arrays'],
        )];
        // A template has no class scope, so `&static` would bind to nothing. It must also not make
        // a `static` Collection and a plain one disagree about the same name.
        yield 'a collection without the late-static marker' => ['collections', $declare(
            " * @var array<int<1, max>, \\Illuminate\\Support\\Collection<int, string>> \$byRole\n"
            . " * @var class-string<\\Illuminate\\Support\\Collection<int<0, 1>, string>> \$kind\n"
            . " * @var \\Illuminate\\Support\\Collection<int<0, 1>, string> \$missing\n",
            self::TEMPLATES['collections'],
        )];

        // The callback's own statement records the producer; the enclosing chain is declined, and
        // its scan for nested view names must not count that same call a second time as unreadable.
        foreach (['modal-arrow', 'modal-closure', 'modal-nested'] as $name) {
            yield "the type a closure inside a declined chain passes ({$name})" => [
                $name,
                $declare(" * @var string \$text\n", self::TEMPLATES[$name]),
            ];
        }
    }

    #[Test]
    #[DataProvider('declaredTemplates')]
    public function declares_what_the_call_sites_agreed_on(string $template, string $expected): void
    {
        $this->assertSame($expected, $this->analyse('annotate')['templates'][$template] ?? null);
    }

    /**
     * Templates the pass must leave alone, each for its own reason. The listing half of what it
     * reports instead is pinned in {@see lists_what_it_leaves_undeclared()}.
     *
     * @return iterable<string, array{string}>
     */
    public static function undeclaredTemplates(): iterable
    {
        yield 'two call sites disagree on the name' => ['conflict'];
        // `@php($heading = ...)` and a closure parameter are template locals, not inputs: declaring
        // either reports MissingViewData at every correct call site. The only call site is one the
        // plugin cannot read, so `$rows` is listed, not declared `mixed`.
        yield 'the names are template locals' => ['locals'];
        // Each view has a closed call site passing a string, so only what surrounds it keeps the
        // declaration out: an open data set, a chain the walk cannot resolve, and an enclosing chain
        // that writes into the view the callback returns.
        yield 'a closure renders the view with an open data set' => ['modal-open'];
        yield 'a closure renders the view through a chain the walk cannot resolve' => ['modal-rendered'];
        yield 'the enclosing chain overwrites the key' => ['modal-invoked'];
        // `Collection<int, Panel>` contradicts a `Collection<int, Panel&static>` caller in the
        // invariant TValue slot, and `Closure(Panel): void` promises to accept more than the caller's
        // `Closure(Panel&static): void`. `get_class()` of a `Collection<int, T>` would write `T` as a
        // class.
        yield 'a late-static marker it cannot drop' => ['panel'];
        yield 'a template behind get_class()' => ['panel-kind'];
        // `view('pkg::spelled::tail')` renders `pkg::spelled` (`ViewName::normalize()` keeps two
        // segments) and `view(' pkg::padded ')` renders `pkg::padded` (the finder trims), each
        // passing another type there.
        yield 'a respelled producer blocks the template it really renders (spelled)' => ['pkg:spelled'];
        yield 'a respelled producer blocks the template it really renders (padded)' => ['pkg:padded'];
    }

    #[Test]
    #[DataProvider('undeclaredTemplates')]
    public function leaves_the_template_undeclared(string $template): void
    {
        $original = \str_starts_with($template, 'pkg:') ? self::PKG_WIDGET : self::TEMPLATES[$template];

        $this->assertSame($original, $this->analyse('annotate')['templates'][$template] ?? null);
    }

    #[Test]
    public function lists_what_it_leaves_undeclared(): void
    {
        $result = $this->annotateResult();
        $site = self::fixture();

        // Two call sites typing a name differently: listed as disagreeing, and no unknown.
        $this->assertNull($this->unknownFor($result, 'conflict'), 'both call sites type it, so it is no unknown');
        $this->assertSame(
            ['conflict' => ['flag' => ['AnnotateFixture\\Post', 'string']]],
            \array_intersect_key((array) ($result['disagreed'] ?? []), ['conflict' => true]),
        );
        // A fourth class and two scalar kinds stay listed beside what the merge declared.
        $this->assertSame(
            [
                'any' => ['AnnotateFixture\\Merger', 'AnnotateFixture\\Panel', 'AnnotateFixture\\Post', 'AnnotateFixture\\Video'],
                'key' => ['int', 'string'],
            ],
            ((array) ($result['disagreed'] ?? []))['merged'] ?? null,
        );
        // Two shapes only a new shape with an optional key covers.
        $this->assertSame(
            ['params' => ['array{a: string, b: string}', 'array{a: string}']],
            ((array) ($result['disagreed'] ?? []))['arrays'] ?? null,
        );
        $this->assertNull($this->unknownFor($result, 'merged'));
        $this->assertNull($this->unknownFor($result, 'collections'), 'a template with nothing unknown has no entry');

        $this->assertSame(['kind'], $this->unknownFor($result, 'panel-kind'));
        $this->assertEqualsCanonicalizing(['family', 'handler'], $this->unknownFor($result, 'panel'));

        // Listed with the call site that left the view open, not as "no call site types them".
        $this->assertNull($this->unknownFor($result, 'modal-open'));
        $this->assertSame(['site' => "{$site}/app/Action.php:48", 'names' => ['text']], $this->unreadableFor($result, 'modal-open'));
        $this->assertSame(['site' => "{$site}/app/Action.php:60", 'names' => ['text']], $this->unreadableFor($result, 'modal-rendered'));
        $this->assertSame(['site' => "{$site}/app/Renderer.php:105", 'names' => ['rows']], $this->unreadableFor($result, 'locals'));

        // The site is the including template's own line, never the compiled shadow's cache path.
        $this->assertSame(['site' => "{$site}/resources/views/scoped-host.blade.php:2", 'names' => ['text']], $this->unreadableFor($result, 'scoped'));
        // #2079: a private helper's bare view is completed by its caller, so it is an open site, not a closed one that supplies nothing.
        $this->assertNull($this->unknownFor($result, 'private-helper'));
        $this->assertSame(['site' => "{$site}/app/HelperView.php:19", 'names' => ['text']], $this->unreadableFor($result, 'private-helper'));

        foreach (['spelled' => 29, 'padded' => 39] as $name => $line) {
            $this->assertSame(['site' => "{$site}/app/Renderer.php:{$line}", 'names' => ['title']], $this->unreadableFor($result, $name), $name);
        }
    }

    #[Test]
    public function an_include_site_counts_as_a_producer_of_the_included_view(): void
    {
        // Below Laravel 12.47 `@includeIsolated` is no directive: the fixture's include stays text.
        if (!\method_exists(BladeCompiler::class, 'compileIncludeIsolated')) {
            $this->markTestSkipped('@includeIsolated needs Laravel 12.47+');
        }

        // The include passes `bool` (the flow type after `??= false`), the controller `true`: declaring
        // the controller's type alone would be a type error at the include; `bool` covers both. The
        // `counter` view's controller passes `bool` too, so the two agree: the include's type is the
        // one after the reassignment, not the including template's declared `true|null`.
        $templates = $this->analyse('annotate')['templates'];

        foreach (['inner', 'counter'] as $name) {
            $this->assertSame("<?php\n/**\n * @var bool \$showCount\n */\n?>\n" . self::TEMPLATES[$name], $templates[$name], $name);
        }

        $this->assertNull($this->unknownFor($this->annotateResult(), 'inner'));
    }

    /**
     * Declarations the pass wrote must hold at the call sites that produced them, checked with one
     * ordinary run over the annotated templates. The control rides in the same run: `merge-control`
     * is given a declaration its call site contradicts, so a report free of the other views' issues
     * is not a check that never ran.
     */
    #[Test]
    public function a_merged_declaration_holds_at_every_call_site_that_produced_it(): void
    {
        $this->assertStringContainsString('@var bool $flag', $this->analyse('annotate')['templates']['merged'], 'the merge must have been written');

        \file_put_contents(self::fixture() . '/resources/views/merge-control.blade.php', "<?php\n/**\n * @var int \$flag\n */\n?>\n{{ \$flag }}\n");

        $report = \json_decode($this->analyseUncached(null)['output'], true);
        $this->assertIsArray($report, 'the ordinary run must report JSON');

        $viewsWithIssues = [];

        foreach ($report as $issue) {
            if (
                \is_array($issue)
                && \in_array($issue['type'] ?? null, ['InvalidViewData', 'MissingViewData'], true)
                && \str_ends_with((string) ($issue['file_name'] ?? ''), 'Merger.php')
                && \preg_match("/^View '([^']+)'/", (string) ($issue['message'] ?? ''), $matches) === 1
            ) {
                $viewsWithIssues[$matches[1]] = true;
            }
        }

        $this->assertSame(['merge-control'], \array_keys($viewsWithIssues), 'only the deliberately contradicted view may report');
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return mixed the names listed as type-unknown for one template
     */
    private function unknownFor(array $result, string $name): mixed
    {
        $this->assertIsArray($result['unknown'] ?? null);

        foreach ($result['unknown'] as $path => $names) {
            if (\str_ends_with((string) $path, "/{$name}.blade.php")) {
                return $names;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return mixed the unreadable-site entry for one template
     */
    private function unreadableFor(array $result, string $name): mixed
    {
        $this->assertIsArray($result['unreadable'] ?? null);

        foreach ($result['unreadable'] as $path => $entry) {
            if (\str_ends_with((string) $path, "/{$name}.blade.php")) {
                return $entry;
            }
        }

        return null;
    }
}
