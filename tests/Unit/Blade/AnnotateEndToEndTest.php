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
 * Every case reads the one memoized annotate run ({@see RunsAnnotateFixture}); the splice mechanics
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

    /** Templates only a second run types: an include under a condition on the variable it passes. */
    private const NEEDS_RERUN = ['narrow-truthy', 'narrow-notnull', 'narrow-isset'];

    /** Templates a second run does write, though not the type the parent holds: they stay out of the fixpoint comparison. */
    private const REWRITTEN_ON_RERUN = ['wr-alias-clause-leaf', 'wr-coalesce-leaf'];

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
     * `pair-top` passes `$member` on to `pair-leaf` through a closed `@includeIsolated`. The parent's
     * type is proven by its controller in the same run, so the child needs no second run to see it.
     */
    #[Test]
    public function types_the_child_of_an_isolated_include_in_the_same_run_as_its_parent(): void
    {
        $this->requireIncludeIsolated();
        $templates = $this->analyse('annotate')['templates'];

        $declaration = $this->declaration('\\AnnotateFixture\\Post', 'member');
        $this->assertSame($declaration . self::TEMPLATES['pair-top'], $templates['pair-top']);
        $this->assertSame($declaration . self::TEMPLATES['pair-leaf'], $templates['pair-leaf']);
        $this->assertNull($this->unknownFor($this->annotateResult(), 'pair-leaf'));
    }

    #[Test]
    public function follows_a_chain_of_isolated_includes_to_its_end_whatever_the_key_is_called(): void
    {
        $this->requireIncludeIsolated();
        $templates = $this->analyse('annotate')['templates'];

        $this->assertSame($this->declaration('\\AnnotateFixture\\Post', 'member') . self::TEMPLATES['chain-top'], $templates['chain-top']);
        $this->assertSame($this->declaration('\\AnnotateFixture\\Post', 'member') . self::TEMPLATES['chain-mid'], $templates['chain-mid']);
        $this->assertSame($this->declaration('\\AnnotateFixture\\Post', 'entry') . self::TEMPLATES['chain-leaf'], $templates['chain-leaf']);
    }

    #[Test]
    public function hands_a_nullable_parent_variable_on_as_nullable(): void
    {
        $this->requireIncludeIsolated();
        $templates = $this->analyse('annotate')['templates'];

        $this->assertSame(
            $this->declaration('\\AnnotateFixture\\Post|null', 'member', ' = null') . self::TEMPLATES['opt-top'],
            $templates['opt-top'],
        );
        // The include always passes the key, so the child requires it.
        $this->assertSame(
            $this->declaration('\\AnnotateFixture\\Post|null', 'member') . self::TEMPLATES['opt-leaf'],
            $templates['opt-leaf'],
        );
    }

    #[Test]
    public function types_nothing_along_a_cycle_of_isolated_includes(): void
    {
        $this->requireIncludeIsolated();
        $templates = $this->analyse('annotate')['templates'];
        $result = $this->annotateResult();

        $this->assertSame(self::TEMPLATES['cyc-a'], $templates['cyc-a']);
        $this->assertSame(self::TEMPLATES['cyc-b'], $templates['cyc-b']);
        $this->assertSame(['AnnotateFixture\\Post', 'mixed'], ((array) ($result['disagreed'] ?? []))['cyc-a']['x'] ?? null);
        $this->assertSame(['x'], $this->unknownFor($result, 'cyc-b'));
    }

    /**
     * Psalm keeps the type id `mixed` after `$x !== null` (and clause-less after `isset($x)`), so an
     * include inside one sees `mixed` just as an unguarded one does. Handing it the parent's `Post|null`
     * would put a type there that a second run, with the parent declared, narrows to `Post`. After
     * `if ($x)` the variable is already `non-empty-mixed`, which was never followed.
     */
    #[Test]
    public function leaves_an_include_under_a_condition_on_the_variable_for_the_next_run(): void
    {
        $this->requireIncludeIsolated();
        $templates = $this->analyse('annotate')['templates'];

        foreach (['narrow-top', 'narrow-plain', 'narrow-isset-top'] as $name) {
            $this->assertSame(
                $this->declaration('\\AnnotateFixture\\Post|null', 'member', $name === 'narrow-plain' ? '' : ' = null') . self::TEMPLATES[$name],
                $templates[$name],
                $name,
            );
        }

        foreach (self::NEEDS_RERUN as $name) {
            $this->assertSame(self::TEMPLATES[$name], $templates[$name], $name);
        }

        // The rerun types them, as the narrowed `Post` the parent's declaration makes of the variable.
        // A real second run over the first one's output: the memoized run cannot stand in for it.
        $rerun = $this->analyseUncached('annotate')['templates'];

        foreach (self::NEEDS_RERUN as $name) {
            $this->assertSame($this->declaration('\\AnnotateFixture\\Post', 'member') . self::TEMPLATES[$name], $rerun[$name], $name);
        }
    }

    /**
     * The parent's own call site passes a typed `member`, which a template that binds the name itself
     * never reads as input: its `$member` is the loop alias or the assignment, `mixed` either way.
     */
    #[Test]
    public function does_not_hand_on_a_variable_the_including_template_binds_itself(): void
    {
        $this->requireIncludeIsolated();
        $templates = $this->analyse('annotate')['templates'];

        foreach (['local-loop', 'local-loop-leaf', 'local-php', 'local-php-leaf'] as $name) {
            $this->assertSame(self::TEMPLATES[$name], $templates[$name], $name);
        }
    }

    #[Test]
    public function does_not_hand_on_a_variable_of_a_template_that_reads_names_it_cannot_list(): void
    {
        $this->requireIncludeIsolated();
        $templates = $this->analyse('annotate')['templates'];

        $this->assertSame(self::TEMPLATES['reads-top'], $templates['reads-top']);
        $this->assertSame(self::TEMPLATES['reads-leaf'], $templates['reads-leaf']);
    }

    /**
     * The parent's call sites disagree, so it declares nothing and the include passes an unknown: the
     * child's own typed call site does not make that a `string` (`mixed` is a producer too).
     */
    #[Test]
    public function counts_an_include_from_a_parent_without_a_type_as_a_producer_of_mixed(): void
    {
        $this->requireIncludeIsolated();
        $templates = $this->analyse('annotate')['templates'];

        $this->assertSame(self::TEMPLATES['split-top'], $templates['split-top']);
        $this->assertSame(self::TEMPLATES['split-leaf'], $templates['split-leaf']);

        $disagreed = (array) ($this->annotateResult()['disagreed'] ?? []);
        $this->assertSame(['member' => ['int', 'string']], $disagreed['split-top'] ?? null);
        $this->assertSame(['member' => ['mixed', 'string']], $disagreed['split-leaf'] ?? null);
    }

    /**
     * `$x ??= ...` writes the variable and keeps the type id `mixed`, and it is no plain assignment,
     * so `$x` is no local: the parent's `Post|null` would be declared in the leaf where the old
     * fixpoint (rerun until nothing changes) writes `Post|mixed`, which no run declares.
     */
    #[Test]
    public function does_not_hand_on_a_variable_the_including_template_writes_with_a_compound_assignment(): void
    {
        $this->requireIncludeIsolated();
        $templates = $this->analyse('annotate')['templates'];

        $this->assertSame(
            $this->declaration('\\AnnotateFixture\\Post|null', 'x', ' = null') . self::TEMPLATES['wr-coalesce'],
            $templates['wr-coalesce'],
            'the parent itself is declared',
        );
        $this->assertSame(self::TEMPLATES['wr-coalesce-leaf'], $templates['wr-coalesce-leaf']);
    }

    /**
     * A condition on `$alias =& $x` names `$alias`, so no clause mentions `$x`; a write through the
     * alias changes `$x` itself. The old fixpoint types the first leaf `Post` (narrowed through the
     * alias) and leaves the second undeclared.
     */
    #[Test]
    public function does_not_hand_on_a_variable_the_including_template_binds_by_reference(): void
    {
        $this->requireIncludeIsolated();
        $templates = $this->analyse('annotate')['templates'];

        $this->assertSame(self::TEMPLATES['wr-alias-clause-leaf'], $templates['wr-alias-clause-leaf']);
        $this->assertSame(self::TEMPLATES['wr-alias-write-leaf'], $templates['wr-alias-write-leaf']);
        $this->assertSame(
            $this->declaration('\\AnnotateFixture\\Post', 'x') . self::TEMPLATES['wr-alias-write'],
            $templates['wr-alias-write'],
            'the parent itself is declared',
        );
    }

    #[Test]
    public function does_not_hand_on_a_variable_the_including_template_unsets_or_references_in_an_array(): void
    {
        $this->requireIncludeIsolated();
        $templates = $this->analyse('annotate')['templates'];

        $this->assertStringContainsString('@var \\AnnotateFixture\\Post $u', $templates['wr-touch'], 'the parent itself is declared');
        $this->assertSame(self::TEMPLATES['wr-touch-unset'], $templates['wr-touch-unset']);
        $this->assertSame(self::TEMPLATES['wr-touch-array'], $templates['wr-touch-array']);
    }

    /**
     * What the first run wrote is the fixpoint: a second run over it changes nothing, but for the
     * includes that were deliberately left for it. The copy as it stands after the memoized first
     * run is the input (other cases may have edited it since), a real run is the output.
     */
    #[Test]
    public function a_second_run_over_annotated_templates_changes_nothing(): void
    {
        $this->analyse('annotate');
        $before = self::templates();
        $after = $this->analyseUncached('annotate')['templates'];

        $this->assertSame(
            \array_diff_key($before, \array_flip([...self::NEEDS_RERUN, ...self::REWRITTEN_ON_RERUN])),
            \array_diff_key($after, \array_flip([...self::NEEDS_RERUN, ...self::REWRITTEN_ON_RERUN])),
        );
    }

    private function requireIncludeIsolated(): void
    {
        // Below Laravel 12.47 `@includeIsolated` is no directive: the fixture's include stays text.
        if (!\method_exists(BladeCompiler::class, 'compileIncludeIsolated')) {
            $this->markTestSkipped('@includeIsolated needs Laravel 12.47+');
        }
    }

    /** The header block the annotator opens in a template that has none. */
    private function declaration(string $type, string $name, string $tail = ''): string
    {
        return "<?php\n/**\n * @var {$type} \${$name}{$tail}\n */\n?>\n";
    }

    /**
     * Declarations the pass wrote must hold at the call sites that produced them. The control: the
     * same run does report a declaration the producers contradict, so the empty list is not a check
     * that never ran.
     */
    #[Test]
    public function a_merged_declaration_holds_at_every_call_site_that_produced_it(): void
    {
        $this->assertStringContainsString('@var bool $flag', $this->analyse('annotate')['templates']['merged'], 'the merge must have been written');

        $this->assertSame([], $this->viewIssuesAtMerger());

        $path = self::fixture() . '/resources/views/merged.blade.php';
        \file_put_contents($path, \str_replace('@var bool $flag', '@var int $flag', (string) \file_get_contents($path)));

        $this->assertNotSame([], $this->viewIssuesAtMerger());
    }

    /** @return list<mixed> InvalidViewData and MissingViewData reported at the merge fixture's call sites */
    private function viewIssuesAtMerger(): array
    {
        $report = \json_decode($this->analyseUncached(null)['output'], true);
        $this->assertIsArray($report, 'the ordinary run must report JSON');

        return \array_values(\array_filter(
            $report,
            static fn(mixed $issue): bool => \is_array($issue)
                && \in_array($issue['type'] ?? null, ['InvalidViewData', 'MissingViewData'], true)
                && \str_ends_with((string) ($issue['file_name'] ?? ''), 'Merger.php'),
        ));
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
