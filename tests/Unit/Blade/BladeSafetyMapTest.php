<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Blade;

use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\BladeSafetyMap;
use Psalm\LaravelPlugin\Blade\BladeUncertaintyReason;
use Psalm\LaravelPlugin\Blade\BladeViewSafetyKind;

final class BladeSafetyMapTest extends TestCase
{
    /** @var list<string> */
    private array $rootsToCleanUp = [];

    /**
     * Initialised in {@see setUp()}, which PHPUnit invokes before each test
     * method. Declared `string` rather than `?string` because every test
     * method dereferences it; a null sentinel would only delay the obvious
     * failure (and trip Psalm's PossiblyNullArgument on every read).
     */
    private string $root = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->root = $this->makeTempRoot();

        \mkdir($this->root . \DIRECTORY_SEPARATOR . 'emails', 0777, true);
        \mkdir($this->root . \DIRECTORY_SEPARATOR . 'posts', 0777, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->rootsToCleanUp as $root) {
            $this->removeTree($root);
        }

        $this->rootsToCleanUp = [];
    }

    public function test_dotted_view_names_are_derived_from_relative_path(): void
    {
        $this->writeBlade($this->root, 'emails/welcome.blade.php', '{!! $banner !!}');

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertSame(['banner'], $map->unsafeKeysFor('emails.welcome'));
        $this->assertSame(BladeViewSafetyKind::UnsafeKeys, $map->safetyFor('emails.welcome')?->kind());
    }

    public function test_nested_subdirectory_produces_multi_segment_view_name(): void
    {
        \mkdir($this->root . '/partials/forms', 0777, true);
        $this->writeBlade($this->root, 'partials/forms/input.blade.php', '{!! $html !!}');

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertSame(['html'], $map->unsafeKeysFor('partials.forms.input'));
    }

    public function test_safe_templates_are_recorded_as_safe(): void
    {
        // Earlier versions only stored unsafe templates, leaving SAFE views
        // indistinguishable from "never scanned". The handler layer needs the
        // distinction to decide between "no sink" and "apply UNKNOWN fallback".
        $this->writeBlade($this->root, 'posts/show.blade.php', '<h1>{{ $post->title }}</h1>');

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertTrue($map->isKnownSafe('posts.show'));
        $this->assertFalse($map->isUnknown('posts.show'));
        $this->assertSame([], $map->unsafeKeysFor('posts.show'));
        $this->assertSame(['posts.show'], $map->knownViews());
        $this->assertSame(BladeViewSafetyKind::Safe, $map->safetyFor('posts.show')?->kind());
    }

    public function test_layout_template_is_recorded_as_unknown(): void
    {
        \mkdir($this->root . \DIRECTORY_SEPARATOR . 'layouts', 0777, true);
        $this->writeBlade(
            $this->root,
            'layouts/app.blade.php',
            "<html><body>@yield('content')</body></html>",
        );

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertTrue($map->isUnknown('layouts.app'));

        $safety = $map->safetyFor('layouts.app');

        $this->assertInstanceOf(\Psalm\LaravelPlugin\Blade\BladeViewSafety::class, $safety);
        $this->assertSame(BladeViewSafetyKind::Unknown, $safety->kind());
        $this->assertContains(BladeUncertaintyReason::LayoutSectionFlow, $safety->uncertainties());
    }

    public function test_multiple_unsafe_keys_are_collected(): void
    {
        $this->writeBlade($this->root, 'emails/digest.blade.php', <<<'BLADE'
            {!! $summaryHtml !!}
            @php echo $rawFooter; @endphp
            BLADE);

        $map = BladeSafetyMap::build([$this->root]);

        $unsafe = $map->unsafeKeysFor('emails.digest');

        \sort($unsafe);

        $this->assertSame(['rawFooter', 'summaryHtml'], $unsafe);
    }

    public function test_first_matching_path_wins_for_unsafe_view(): void
    {
        // Laravel's FileViewFinder iterates paths in order and returns the
        // first match. BladeSafetyMap mirrors that: the first occurrence of a
        // given view name wins.
        $altRoot = $this->makeTempRoot();
        \mkdir($altRoot . \DIRECTORY_SEPARATOR . 'emails', 0777, true);

        $this->writeBlade($this->root, 'emails/welcome.blade.php', '{!! $primary !!}');
        $this->writeBlade($altRoot, 'emails/welcome.blade.php', '{!! $override !!}');

        $map = BladeSafetyMap::build([$this->root, $altRoot]);

        $this->assertSame(['primary'], $map->unsafeKeysFor('emails.welcome'));
    }

    public function test_first_matching_path_wins_when_first_is_safe(): void
    {
        // Regression for the earlier PoC: when the first root had a SAFE view
        // and a later root had an UNSAFE view, the map skipped the SAFE one
        // and stored the UNSAFE shadow — which Laravel would never render.
        // The map must record the first match regardless of safety kind.
        $altRoot = $this->makeTempRoot();
        \mkdir($altRoot . \DIRECTORY_SEPARATOR . 'emails', 0777, true);

        $this->writeBlade($this->root, 'emails/welcome.blade.php', '<h1>{{ $title }}</h1>');
        $this->writeBlade($altRoot, 'emails/welcome.blade.php', '{!! $injected !!}');

        $map = BladeSafetyMap::build([$this->root, $altRoot]);

        $this->assertTrue($map->isKnownSafe('emails.welcome'));
        $this->assertSame([], $map->unsafeKeysFor('emails.welcome'));
    }

    public function test_view_in_second_path_is_picked_up_when_absent_from_first(): void
    {
        $altRoot = $this->makeTempRoot();
        \mkdir($altRoot . \DIRECTORY_SEPARATOR . 'emails', 0777, true);

        // Only in the second path — must still be discovered.
        $this->writeBlade($altRoot, 'emails/overflow.blade.php', '{!! $content !!}');

        $map = BladeSafetyMap::build([$this->root, $altRoot]);

        $this->assertSame(['content'], $map->unsafeKeysFor('emails.overflow'));
    }

    public function test_non_blade_siblings_are_ignored(): void
    {
        // Editor swap files and legacy .php views should not be parsed as
        // blade templates. str_ends_with ensures `foo.blade.php.bak` is out.
        $this->writeBlade($this->root, 'emails/legit.blade.php', '{!! $html !!}');
        \file_put_contents($this->root . '/emails/legacy.php', '<?= $neverScanned ?>');
        \file_put_contents($this->root . '/emails/legit.blade.php.bak', '{!! $leaked !!}');
        \file_put_contents($this->root . '/emails/legit.blade.php~', '{!! $alsoLeaked !!}');

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertSame(['emails.legit'], $map->knownViews());
        $this->assertSame(['html'], $map->unsafeKeysFor('emails.legit'));
    }

    public function test_missing_view_directory_is_silently_skipped(): void
    {
        // Build tolerates a configured-but-not-yet-created view root. Matches
        // the permissive behaviour of MissingViewHandler::viewFileExists().
        $map = BladeSafetyMap::build(['/nonexistent/path/to/views']);

        $this->assertSame([], $map->knownViews());
    }

    public function test_unknown_view_name_returns_null_from_safety_for(): void
    {
        $map = BladeSafetyMap::build([$this->root]);

        $this->assertNotInstanceOf(\Psalm\LaravelPlugin\Blade\BladeViewSafety::class, $map->safetyFor('never.scanned'));
        $this->assertFalse($map->isKnownSafe('never.scanned'));
        $this->assertFalse($map->isUnknown('never.scanned'));
        $this->assertSame([], $map->unsafeKeysFor('never.scanned'));
    }

    public function test_include_literal_target_propagates_child_unsafe_keys_through_explicit_data_array(): void
    {
        // Parent calls `@include('partials.row', ['html' => $bio])`. Child's
        // unsafe key 'html' is mapped through the parent's explicit array to
        // the parent variable $bio. The parent's unsafe-keys gain 'bio' AND
        // every other child unsafe key not bound by the explicit array
        // (mergeData verbatim pass-through).
        \mkdir($this->root . \DIRECTORY_SEPARATOR . 'partials', 0777, true);

        $this->writeBlade($this->root, 'partials/row.blade.php', '{!! $html !!} {!! $secret !!}');
        $this->writeBlade(
            $this->root,
            'posts/show.blade.php',
            "@include('partials.row', ['html' => \$bio])",
        );

        $map = BladeSafetyMap::build([$this->root]);

        $parentSafety = $map->safetyFor('posts.show');

        $this->assertInstanceOf(\Psalm\LaravelPlugin\Blade\BladeViewSafety::class, $parentSafety);
        $this->assertSame(BladeViewSafetyKind::UnsafeKeys, $parentSafety->kind());

        $unsafe = $parentSafety->unsafeKeys();
        \sort($unsafe);

        // - 'html' was bound to $bio → parent gains 'bio'.
        // - 'secret' was NOT bound → parent gains 'secret' verbatim (mergeData).
        $this->assertSame(['bio', 'secret'], $unsafe);
    }

    public function test_include_with_no_explicit_data_propagates_child_keys_verbatim(): void
    {
        // 2-arg `@include('partials.row')` — no explicit data array, so every
        // child unsafe key reaches the parent as the parent's same-named
        // variable via mergeData.
        \mkdir($this->root . \DIRECTORY_SEPARATOR . 'partials', 0777, true);

        $this->writeBlade($this->root, 'partials/row.blade.php', '{!! $html !!}');
        $this->writeBlade($this->root, 'posts/show.blade.php', "@include('partials.row')");

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertSame(['html'], $map->unsafeKeysFor('posts.show'));
        $this->assertSame(BladeViewSafetyKind::UnsafeKeys, $map->safetyFor('posts.show')?->kind());
    }

    public function test_include_into_safe_child_leaves_parent_safe(): void
    {
        // A literal `@include` of a SAFE template propagates zero unsafe keys.
        // The parent is flipped from UNKNOWN(IncludeResolved) to SAFE.
        \mkdir($this->root . \DIRECTORY_SEPARATOR . 'partials', 0777, true);

        $this->writeBlade($this->root, 'partials/row.blade.php', '<p>{{ $html }}</p>');
        $this->writeBlade($this->root, 'posts/show.blade.php', "@include('partials.row')");

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertTrue($map->isKnownSafe('posts.show'));
        $this->assertSame([], $map->unsafeKeysFor('posts.show'));
    }

    public function test_include_with_non_literal_target_keeps_parent_unknown(): void
    {
        // Dynamic include target: scanner cannot resolve the child, so the
        // propagation pass cannot run. Parent stays UNKNOWN(IncludeDirective).
        $this->writeBlade($this->root, 'posts/show.blade.php', "@include(\$partial, ['x' => \$y])");

        $map = BladeSafetyMap::build([$this->root]);

        $safety = $map->safetyFor('posts.show');

        $this->assertInstanceOf(\Psalm\LaravelPlugin\Blade\BladeViewSafety::class, $safety);
        $this->assertSame(BladeViewSafetyKind::Unknown, $safety->kind());
        $this->assertContains(BladeUncertaintyReason::IncludeDirective, $safety->uncertainties());
    }

    public function test_include_with_non_literal_data_array_keeps_parent_unknown(): void
    {
        // Literal target, dynamic data array (`$data`): the explicit key
        // binding is unenumerable, so the scanner emits IncludeDirective
        // rather than IncludeResolved. Parent stays UNKNOWN.
        \mkdir($this->root . \DIRECTORY_SEPARATOR . 'partials', 0777, true);

        $this->writeBlade($this->root, 'partials/row.blade.php', '{!! $html !!}');
        $this->writeBlade($this->root, 'posts/show.blade.php', "@include('partials.row', \$data)");

        $map = BladeSafetyMap::build([$this->root]);

        $safety = $map->safetyFor('posts.show');

        $this->assertInstanceOf(\Psalm\LaravelPlugin\Blade\BladeViewSafety::class, $safety);
        $this->assertSame(BladeViewSafetyKind::Unknown, $safety->kind());
        $this->assertContains(BladeUncertaintyReason::IncludeDirective, $safety->uncertainties());
    }

    public function test_include_into_unknown_child_keeps_parent_unknown(): void
    {
        // Child is UNKNOWN (its own LayoutSectionFlow uncertainty). Parent's
        // contribution from the include is opaque → parent stays UNKNOWN
        // even though the include itself was statically resolvable.
        \mkdir($this->root . \DIRECTORY_SEPARATOR . 'partials', 0777, true);

        $this->writeBlade(
            $this->root,
            'partials/row.blade.php',
            "@yield('chunk')",
        );
        $this->writeBlade($this->root, 'posts/show.blade.php', "@include('partials.row')");

        $map = BladeSafetyMap::build([$this->root]);

        $safety = $map->safetyFor('posts.show');

        $this->assertInstanceOf(\Psalm\LaravelPlugin\Blade\BladeViewSafety::class, $safety);
        $this->assertSame(BladeViewSafetyKind::Unknown, $safety->kind());
        $this->assertContains(BladeUncertaintyReason::IncludeDirective, $safety->uncertainties());
    }

    public function test_include_into_unknown_child_does_not_propagate_local_unsafe_keys(): void
    {
        // Even when a child is UNKNOWN, the propagation pass treats the
        // include's contribution as opaque rather than reusing the child's
        // local unsafe-keys list. The parent's localUnsafeKeys are
        // preserved on the resulting safety record (for diagnostics) but
        // they do not include the child's keys.
        \mkdir($this->root . \DIRECTORY_SEPARATOR . 'partials', 0777, true);

        $this->writeBlade(
            $this->root,
            'partials/row.blade.php',
            "{!! \$inner !!} @yield('chunk')",
        );
        $this->writeBlade(
            $this->root,
            'posts/show.blade.php',
            "{!! \$parentRaw !!} @include('partials.row')",
        );

        $map = BladeSafetyMap::build([$this->root]);

        $safety = $map->safetyFor('posts.show');

        $this->assertInstanceOf(\Psalm\LaravelPlugin\Blade\BladeViewSafety::class, $safety);
        $this->assertSame(BladeViewSafetyKind::Unknown, $safety->kind());
        // The parent's local raw echo of $parentRaw is preserved.
        $this->assertContains('parentRaw', $safety->unsafeKeys());
        // But the child's 'inner' key does NOT leak through opaque UNKNOWN.
        $this->assertNotContains('inner', $safety->unsafeKeys());
    }

    public function test_include_cycle_marks_every_member_unknown_include_cycle(): void
    {
        // a → b → a. Both participate; both become UNKNOWN(IncludeCycle).
        $this->writeBlade($this->root, 'posts/a.blade.php', "@include('posts.b')");
        $this->writeBlade($this->root, 'posts/b.blade.php', "@include('posts.a')");

        $map = BladeSafetyMap::build([$this->root]);

        foreach (['posts.a', 'posts.b'] as $name) {
            $safety = $map->safetyFor($name);

            $this->assertInstanceOf(\Psalm\LaravelPlugin\Blade\BladeViewSafety::class, $safety);
            $this->assertSame(BladeViewSafetyKind::Unknown, $safety->kind());
            $this->assertContains(BladeUncertaintyReason::IncludeCycle, $safety->uncertainties());
        }
    }

    public function test_include_self_loop_is_marked_include_cycle(): void
    {
        $this->writeBlade($this->root, 'posts/self.blade.php', "@include('posts.self')");

        $map = BladeSafetyMap::build([$this->root]);

        $safety = $map->safetyFor('posts.self');

        $this->assertInstanceOf(\Psalm\LaravelPlugin\Blade\BladeViewSafety::class, $safety);
        $this->assertSame(BladeViewSafetyKind::Unknown, $safety->kind());
        $this->assertContains(BladeUncertaintyReason::IncludeCycle, $safety->uncertainties());
    }

    public function test_include_with_other_uncertainty_strips_include_resolved_marker(): void
    {
        // Parent has BOTH a literal `@include` (would normally produce
        // IncludeResolved) AND another uncertainty (@yield → LayoutSectionFlow).
        // The propagation pass is non-eligible (uncertainty list contains a
        // non-IncludeResolved entry), so propagation is skipped. The
        // post-build safety record must NOT expose IncludeResolved — that
        // marker is documented as intermediate-only via
        // {@see BladeUncertaintyReason::IncludeResolved}'s class docblock.
        \mkdir($this->root . \DIRECTORY_SEPARATOR . 'partials', 0777, true);

        $this->writeBlade($this->root, 'partials/row.blade.php', '{!! $html !!}');
        $this->writeBlade(
            $this->root,
            'posts/show.blade.php',
            "@yield('chunk')\n@include('partials.row')",
        );

        $map = BladeSafetyMap::build([$this->root]);

        $safety = $map->safetyFor('posts.show');

        $this->assertInstanceOf(\Psalm\LaravelPlugin\Blade\BladeViewSafety::class, $safety);
        $this->assertSame(BladeViewSafetyKind::Unknown, $safety->kind());
        $this->assertContains(BladeUncertaintyReason::LayoutSectionFlow, $safety->uncertainties());
        $this->assertNotContains(
            BladeUncertaintyReason::IncludeResolved,
            $safety->uncertainties(),
            'IncludeResolved is an intermediate marker and must not leak into the public map.',
        );
    }

    public function test_include_chain_propagates_through_multiple_hops(): void
    {
        // a includes b, b includes c. c has unsafe key 'html'. The
        // propagation pass folds c → b → a so a gains 'html'.
        \mkdir($this->root . \DIRECTORY_SEPARATOR . 'partials', 0777, true);

        $this->writeBlade($this->root, 'partials/c.blade.php', '{!! $html !!}');
        $this->writeBlade($this->root, 'partials/b.blade.php', "@include('partials.c')");
        $this->writeBlade($this->root, 'posts/a.blade.php', "@include('partials.b')");

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertSame(['html'], $map->unsafeKeysFor('posts.a'));
        $this->assertSame(['html'], $map->unsafeKeysFor('partials.b'));
        $this->assertSame(['html'], $map->unsafeKeysFor('partials.c'));
    }

    public function test_include_with_missing_child_view_keeps_parent_unknown(): void
    {
        // Child view does not exist on disk (typo). Propagation cannot
        // proceed; parent stays UNKNOWN(IncludeDirective).
        $this->writeBlade($this->root, 'posts/show.blade.php', "@include('missing.partial')");

        $map = BladeSafetyMap::build([$this->root]);

        $safety = $map->safetyFor('posts.show');

        $this->assertInstanceOf(\Psalm\LaravelPlugin\Blade\BladeViewSafety::class, $safety);
        $this->assertSame(BladeViewSafetyKind::Unknown, $safety->kind());
        $this->assertContains(BladeUncertaintyReason::IncludeDirective, $safety->uncertainties());
    }

    public function test_unreadable_blade_file_is_recorded_as_unknown(): void
    {
        // Verifies the FILE_UNREADABLE branch in BladeSafetyMap::build().
        // chmod 0000 is ineffective on Windows and as root, so we skip in
        // those environments rather than fake the test.
        if (\PHP_OS_FAMILY === 'Windows' || (\function_exists('posix_geteuid') && \posix_geteuid() === 0)) {
            $this->markTestSkipped('chmod 0000 not effective on Windows or as root');
        }

        $path = $this->root . \DIRECTORY_SEPARATOR . 'emails' . \DIRECTORY_SEPARATOR . 'locked.blade.php';
        \file_put_contents($path, '{!! $banner !!}');
        \chmod($path, 0000);

        try {
            $map = BladeSafetyMap::build([$this->root]);

            $this->assertTrue($map->isUnknown('emails.locked'));
            $safety = $map->safetyFor('emails.locked');
            $this->assertInstanceOf(\Psalm\LaravelPlugin\Blade\BladeViewSafety::class, $safety);
            $this->assertContains(
                BladeUncertaintyReason::FileUnreadable,
                $safety->uncertainties(),
            );
        } finally {
            // Restore so the recursive teardown can unlink the file.
            \chmod($path, 0644);
        }
    }

    // ----- PR-6b: anonymous component propagation -----

    public function test_component_edge_propagates_bound_var_to_parent(): void
    {
        \mkdir($this->root . '/components', 0777, true);

        $this->writeBlade(
            $this->root,
            'components/card.blade.php',
            '{!! $bio !!}',
        );

        $this->writeBlade(
            $this->root,
            'posts/show.blade.php',
            '<x-card :bio="$post" />',
        );

        $map = BladeSafetyMap::build([$this->root]);

        // Child's unsafe key `bio` maps through the edge's `bio => [post]`
        // binding. Parent's unsafe keys gain `post`.
        $this->assertSame(['post'], $map->unsafeKeysFor('posts.show'));
        $this->assertSame(['bio'], $map->unsafeKeysFor('components.card'));
    }

    public function test_component_edge_with_static_attribute_does_not_propagate(): void
    {
        // `<x-card bio="literal" />` binds `bio` to a non-parent value.
        // Component edges have no mergeData pass-through, so the child's
        // unsafe key `bio` must NOT propagate as parent `bio`.
        \mkdir($this->root . '/components', 0777, true);

        $this->writeBlade(
            $this->root,
            'components/card.blade.php',
            '{!! $bio !!}',
        );

        $this->writeBlade(
            $this->root,
            'posts/show.blade.php',
            '<x-card bio="literal" />',
        );

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertTrue($map->isKnownSafe('posts.show'));
    }

    public function test_component_child_unsafe_key_not_bound_by_parent_is_dropped(): void
    {
        // Child raw-echoes `$other`, but the parent does not bind `other`.
        // Components have no mergeData fallthrough, so the parent does NOT
        // gain `other` as an unsafe key.
        \mkdir($this->root . '/components', 0777, true);

        $this->writeBlade(
            $this->root,
            'components/card.blade.php',
            '{!! $other !!}',
        );

        $this->writeBlade(
            $this->root,
            'posts/show.blade.php',
            '<x-card :bio="$post" />',
        );

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertTrue($map->isKnownSafe('posts.show'));
    }

    public function test_component_kebab_case_attribute_maps_to_child_camel_name(): void
    {
        // `<x-card :user-name="$user" />` binds `$userName` in the child
        // anonymous template. The child raw-echoes `$userName`, so the
        // parent's contribution is `user`.
        \mkdir($this->root . '/components', 0777, true);

        $this->writeBlade(
            $this->root,
            'components/card.blade.php',
            '{!! $userName !!}',
        );

        $this->writeBlade(
            $this->root,
            'posts/show.blade.php',
            '<x-card :user-name="$user" />',
        );

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertSame(['user'], $map->unsafeKeysFor('posts.show'));
    }

    public function test_component_with_no_candidate_match_falls_back_to_unknown(): void
    {
        // No `components/missing*.blade.php` exists in any scan root, so
        // the component edge's candidate list resolves to none of the
        // scanned templates. Parent surfaces UNKNOWN(ComponentTag).
        $this->writeBlade(
            $this->root,
            'posts/show.blade.php',
            '<x-missing :bio="$post" />',
        );

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertTrue($map->isUnknown('posts.show'));
        $this->assertContains(
            BladeUncertaintyReason::ComponentTag,
            $map->safetyFor('posts.show')?->analysis->uncertainties ?? [],
        );
    }

    public function test_component_resolves_to_index_candidate_when_first_is_missing(): void
    {
        // Laravel's anonymous resolver tries `components.card`,
        // `components.card.index`, then `components.card.card`. Only the
        // `.index` variant exists in this fixture, so propagation should
        // pick it.
        \mkdir($this->root . '/components/card', 0777, true);

        $this->writeBlade(
            $this->root,
            'components/card/index.blade.php',
            '{!! $bio !!}',
        );

        $this->writeBlade(
            $this->root,
            'posts/show.blade.php',
            '<x-card :bio="$post" />',
        );

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertSame(['post'], $map->unsafeKeysFor('posts.show'));
    }

    public function test_self_referencing_component_is_marked_include_cycle(): void
    {
        // `components/loop.blade.php` includes itself via `<x-loop />`.
        // Cycle detection (which now walks component edges) flags it.
        \mkdir($this->root . '/components', 0777, true);

        $this->writeBlade(
            $this->root,
            'components/loop.blade.php',
            '<x-loop />',
        );

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertTrue($map->isUnknown('components.loop'));
        $this->assertContains(
            BladeUncertaintyReason::IncludeCycle,
            $map->safetyFor('components.loop')?->analysis->uncertainties ?? [],
        );
    }

    public function test_intermediate_component_resolved_marker_never_leaks_to_consumers(): void
    {
        // A template with mixed uncertainties (ComponentResolved alongside
        // a real LayoutSectionFlow) is non-eligible for propagation. The
        // exposed safety record must NOT carry the intermediate
        // ComponentResolved marker.
        \mkdir($this->root . '/components', 0777, true);

        $this->writeBlade(
            $this->root,
            'components/card.blade.php',
            '{!! $bio !!}',
        );

        $this->writeBlade(
            $this->root,
            'posts/show.blade.php',
            "@yield('content')\n<x-card :bio=\"\$post\" />",
        );

        $map = BladeSafetyMap::build([$this->root]);

        $uncertainties = $map->safetyFor('posts.show')?->analysis->uncertainties ?? [];

        $this->assertContains(BladeUncertaintyReason::LayoutSectionFlow, $uncertainties);
        $this->assertNotContains(BladeUncertaintyReason::ComponentResolved, $uncertainties);
    }

    public function test_component_edge_with_unknown_child_falls_back_to_unknown(): void
    {
        // Child anonymous component itself includes a dynamic-target
        // `@include($x)`, so the child resolves to UNKNOWN(IncludeDirective).
        // Parent's contribution from the component edge is opaque, so the
        // parent surfaces UNKNOWN(ComponentTag).
        \mkdir($this->root . '/components', 0777, true);

        $this->writeBlade(
            $this->root,
            'components/card.blade.php',
            '@include($child)',
        );

        $this->writeBlade(
            $this->root,
            'posts/show.blade.php',
            '<x-card :bio="$post" />',
        );

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertTrue($map->isUnknown('posts.show'));
        $this->assertContains(
            BladeUncertaintyReason::ComponentTag,
            $map->safetyFor('posts.show')?->analysis->uncertainties ?? [],
        );
    }

    public function test_cross_edge_type_cycle_is_detected(): void
    {
        // Parent `posts/show` does `@include('partials.row')`; the include
        // target does `<x-show />` back to the parent. The unified cycle
        // DFS walks both edge types, so the cycle is detected; splitting
        // detection into two passes (an attractive refactor) would miss it.
        \mkdir($this->root . '/components', 0777, true);
        \mkdir($this->root . '/partials', 0777, true);

        $this->writeBlade(
            $this->root,
            'partials/row.blade.php',
            '<x-show />',
        );

        \mkdir($this->root . '/components/show', 0777, true);
        $this->writeBlade(
            $this->root,
            'components/show.blade.php',
            "@include('partials.row')",
        );

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertContains(
            BladeUncertaintyReason::IncludeCycle,
            $map->safetyFor('components.show')?->analysis->uncertainties ?? [],
        );
        $this->assertContains(
            BladeUncertaintyReason::IncludeCycle,
            $map->safetyFor('partials.row')?->analysis->uncertainties ?? [],
        );
    }

    public function test_first_candidate_wins_when_multiple_exist(): void
    {
        // Both `components/card.blade.php` AND `components/card/index.blade.php`
        // exist on disk. Laravel's first-match resolver picks `components.card`.
        // The plugin must match: a future ordering change in the candidate list
        // would silently swap which template's unsafe keys flow.
        \mkdir($this->root . '/components/card', 0777, true);

        $this->writeBlade(
            $this->root,
            'components/card.blade.php',
            '{!! $firstWin !!}',
        );

        $this->writeBlade(
            $this->root,
            'components/card/index.blade.php',
            '{!! $secondWin !!}',
        );

        $this->writeBlade(
            $this->root,
            'posts/show.blade.php',
            '<x-card :first-win="$alpha" :second-win="$beta" />',
        );

        $map = BladeSafetyMap::build([$this->root]);

        $keys = $map->unsafeKeysFor('posts.show');

        $this->assertContains('alpha', $keys);
        $this->assertNotContains('beta', $keys);
    }

    public function test_multi_hop_component_chain_propagates_through_resolvable_intermediates(): void
    {
        // Parent → outer → inner. Component edges chain across multiple
        // hops. Component propagation differs enough from include
        // propagation (no mergeData fallthrough) that breaking the
        // transitive case would not be caught by single-hop tests.
        \mkdir($this->root . '/components', 0777, true);

        $this->writeBlade(
            $this->root,
            'components/inner.blade.php',
            '{!! $payload !!}',
        );

        $this->writeBlade(
            $this->root,
            'components/outer.blade.php',
            '<x-inner :payload="$payload" />',
        );

        $this->writeBlade(
            $this->root,
            'posts/show.blade.php',
            '<x-outer :payload="$post" />',
        );

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertSame(['post'], $map->unsafeKeysFor('posts.show'));
    }

    public function test_attributes_bag_reads_propagate_every_bound_parent_var(): void
    {
        // Child anonymous component reads `$attributes->get('bio')`, which
        // returns the parent's bound `:bio="$tainted"` WITHOUT escaping.
        // The standard "child key must be in explicitKeyMap" propagation
        // gate would drop the flow because `attributes` is a reserved
        // scope-local, never a parent-bound attribute name. Special-cased
        // in `propagateComponentChildKey` to union every parent var bound
        // on the edge.
        //
        // Multiple bindings on the same edge MUST all union: the child's
        // `$attributes->get('bio')` could resolve to either at runtime,
        // and the bag API also supports `$attributes->only([...])` /
        // `$attributes->whereStartsWith(...)` which can read any subset.
        // Asserting both parent vars verifies the implementation's outer
        // `foreach ($explicitKeyMap as $parentVars)` actually iterates
        // every binding, not just the first.
        \mkdir($this->root . '/components', 0777, true);

        $this->writeBlade(
            $this->root,
            'components/card.blade.php',
            "{!! \$attributes->get('bio') !!}",
        );

        $this->writeBlade(
            $this->root,
            'posts/show.blade.php',
            '<x-card :bio="$alpha" :name="$beta" />',
        );

        $map = BladeSafetyMap::build([$this->root]);

        $keys = $map->unsafeKeysFor('posts.show');

        $this->assertContains('alpha', $keys);
        $this->assertContains('beta', $keys);
    }

    public function test_bound_attribute_wins_over_duplicate_static_for_same_camelized_name(): void
    {
        // `<x-card bar="literal" :bar="$x" />` is a duplicate-attribute form
        // Laravel resolves last-write-wins via `mapWithKeys()`. The parser
        // emits both forms (source order is lost in partitioning); the
        // scanner's static loop must NOT clobber the bound entry. Erring
        // toward the bound form is sound (over-reports taint at worst).
        \mkdir($this->root . '/components', 0777, true);

        $this->writeBlade(
            $this->root,
            'components/card.blade.php',
            '{!! $bar !!}',
        );

        $this->writeBlade(
            $this->root,
            'posts/show.blade.php',
            '<x-card bar="literal" :bar="$tainted" />',
        );

        $map = BladeSafetyMap::build([$this->root]);

        $this->assertContains('tainted', $map->unsafeKeysFor('posts.show'));
    }

    public function test_parent_with_both_include_and_component_edges_propagates_both(): void
    {
        // Mixed parent: `@include('partials.foo', ['x' => $a])` AND
        // `<x-card :bio="$post" />`. Eligibility allows
        // {IncludeResolved, ComponentResolved}, so both edges propagate.
        \mkdir($this->root . '/components', 0777, true);
        \mkdir($this->root . '/partials', 0777, true);

        $this->writeBlade(
            $this->root,
            'partials/foo.blade.php',
            '{!! $x !!}',
        );

        $this->writeBlade(
            $this->root,
            'components/card.blade.php',
            '{!! $bio !!}',
        );

        $this->writeBlade(
            $this->root,
            'posts/show.blade.php',
            "@include('partials.foo', ['x' => \$a])\n<x-card :bio=\"\$post\" />",
        );

        $map = BladeSafetyMap::build([$this->root]);

        $keys = $map->unsafeKeysFor('posts.show');

        $this->assertContains('a', $keys);
        $this->assertContains('post', $keys);
    }

    private function writeBlade(string $root, string $relativePath, string $contents): void
    {
        \file_put_contents($root . \DIRECTORY_SEPARATOR . $relativePath, $contents);
    }

    private function makeTempRoot(): string
    {
        $root = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'blade-safety-map-' . \uniqid('', true);

        \mkdir($root, 0777, true);

        $this->rootsToCleanUp[] = $root;

        return $root;
    }

    /**
     * Recursively remove a directory tree. Intentionally tolerant of
     * already-missing paths so teardown stays idempotent.
     */
    private function removeTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                \rmdir($file->getPathname());
            } else {
                \unlink($file->getPathname());
            }
        }

        \rmdir($path);
    }
}
