<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\FileViewFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\BladeBootstrapper;
use Psalm\LaravelPlugin\Blade\ContractRegistry;
use Psalm\LaravelPlugin\Blade\PreludeBuilder;
use Psalm\LaravelPlugin\Blade\ShadowRegistry;
use Psalm\LaravelPlugin\Blade\ViewReferenceRegistry;

#[CoversClass(BladeBootstrapper::class)]
final class BladeBootstrapperTest extends TestCase
{
    private string $root;

    private string $viewDir;

    private string $shadowDir;

    private RecordingProgress $progress;

    protected function setUp(): void
    {
        $root = \tempnam(\sys_get_temp_dir(), 'blade-boot-');
        $this->assertIsString($root);
        \unlink($root);
        \mkdir($root . '/views', 0o777, true);

        $this->root = $root;
        $this->viewDir = $root . '/views';
        $this->shadowDir = $root . '/cache/blade';
        $this->progress = new RecordingProgress();

        // This suite calls BladeBootstrapper directly, bypassing Plugin::resetInvocationState(), so
        // the registries have to be reset here or an earlier test's compile failure/registration
        // leaks into this one.
        ViewReferenceRegistry::reset();
        ContractRegistry::reset();
        ShadowRegistry::reset();
    }

    protected function tearDown(): void
    {
        $this->deleteRecursively($this->root);
    }

    private function deleteRecursively(string $path): void
    {
        // A symlink must be unlinked, never descended into or rmdir'd: descending would delete
        // through the link and rmdir() refuses a link.
        if (\is_link($path) || \is_file($path)) {
            \unlink($path);

            return;
        }

        if (!\is_dir($path)) {
            return;
        }

        foreach (\array_diff(\scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->deleteRecursively($path . '/' . $entry);
        }

        \rmdir($path);
    }

    private function writeTemplate(string $name, string $contents): string
    {
        $path = $this->viewDir . '/' . $name;
        \mkdir(\dirname($path), 0o777, true);
        \file_put_contents($path, $contents);

        return (string) \realpath($path);
    }

    /** A container with both bindings the bootstrapper needs. */
    private function app(): Container
    {
        $container = new Container();
        $container->instance('blade.compiler', new BladeCompiler(new Filesystem(), $this->root . '/compiled'));
        $container->instance('view.finder', new FileViewFinder(new Filesystem(), [$this->viewDir]));

        return $container;
    }

    private function bootstrapper(Container $app, RecordingShadowRegistrar $registrar): BladeBootstrapper
    {
        return new BladeBootstrapper($app, $registrar, $this->progress, $this->shadowDir);
    }

    /**
     * True when a directory's upper/lower-cased spelling also resolves to it, on THIS filesystem.
     * Case (in)sensitivity is a filesystem capability, never assumed from the OS: this is what
     * gates the two case-variant tests below to whichever half of the pair this machine can hold.
     */
    private function filesystemIsCaseInsensitive(): bool
    {
        $lower = $this->root . '/case-probe';
        \mkdir($lower, 0o777, true);

        return \is_dir($this->root . '/CASE-PROBE');
    }

    #[Test]
    public function compiles_a_template_and_registers_both_sides_with_psalm(): void
    {
        $template = $this->writeTemplate('profile.blade.php', "<p>{{ \$name }}</p>\n");
        $registrar = new RecordingShadowRegistrar();

        $this->bootstrapper($this->app(), $registrar)->boot();

        $this->assertSame([], $this->progress->warnings, $this->progress->warningText());
        $this->assertSame([$template], $registrar->reportableTemplates, 'the blade path is what becomes reportable');
        $this->assertCount(1, $registrar->analyzedShadows);

        $shadow = $registrar->analyzedShadows[0];
        $this->assertStringStartsWith($this->shadowDir, $shadow, 'shadows belong in the configured cache dir');
        $this->assertNotSame($template, $shadow, 'the shadow is never the blade path itself');
        $this->assertFileExists($shadow);
        $this->assertStringContainsString('echo e($name)', (string) \file_get_contents($shadow));
        $this->assertFileExists($this->shadowDir . '/manifest.php');
    }

    /**
     * `loadViewsFrom($dir, $namespace)` puts its directory in the finder's `getHints()`, never
     * `getPaths()` — a template that lives ONLY there was invisible to discovery entirely (#1497).
     * It also has to register under the QUALIFIED `ns::name`, never the bare dotted name: call
     * sites store `view('pkg::widget')` verbatim, so an unqualified registration both misses the
     * real name and fabricates one nothing ever resolves to.
     */
    #[Test]
    public function discovers_a_template_registered_only_through_a_finder_namespace_hint(): void
    {
        $hintDir = $this->root . '/package-views';
        \mkdir($hintDir, 0o777, true);
        $template = $hintDir . '/widget.blade.php';
        \file_put_contents($template, "<p>{{ \$name }}</p>\n");
        $template = (string) \realpath($template);

        $finder = new FileViewFinder(new Filesystem(), [$this->viewDir]);
        $finder->addNamespace('pkg', $hintDir);

        $app = new Container();
        $app->instance('blade.compiler', new BladeCompiler(new Filesystem(), $this->root . '/compiled'));
        $app->instance('view.finder', $finder);

        $registrar = new RecordingShadowRegistrar();

        $this->bootstrapper($app, $registrar)->boot();

        $this->assertSame([], $this->progress->warnings, $this->progress->warningText());
        $this->assertSame([$template], $registrar->reportableTemplates);
        $this->assertCount(1, $registrar->analyzedShadows);
        $this->assertNotNull(ContractRegistry::contractFor('pkg::widget'), 'must register under the qualified name');
        $this->assertNull(ContractRegistry::contractFor('widget'), 'must not also register unqualified');
    }

    /**
     * A published override (`resources/views/vendor/pkg/widget.blade.php`) sits under BOTH the main
     * root and the namespace's first hint — the same file legitimately owns two names,
     * `vendor.pkg.widget` and `pkg::widget`. `ViewName::resolve()` used to return only the first
     * matching root, silently dropping the qualified name a caller might use.
     */
    #[Test]
    public function a_published_override_registers_under_both_its_qualified_and_unqualified_name(): void
    {
        $overrideDir = $this->viewDir . '/vendor/pkg';
        \mkdir($overrideDir, 0o777, true);
        $template = $overrideDir . '/widget.blade.php';
        \file_put_contents($template, "<p>{{ \$name }}</p>\n");
        \realpath($template);

        $finder = new FileViewFinder(new Filesystem(), [$this->viewDir]);
        $finder->addNamespace('pkg', $overrideDir);

        $app = new Container();
        $app->instance('blade.compiler', new BladeCompiler(new Filesystem(), $this->root . '/compiled'));
        $app->instance('view.finder', $finder);

        $this->bootstrapper($app, new RecordingShadowRegistrar())->boot();

        $this->assertSame([], $this->progress->warnings, $this->progress->warningText());
        $this->assertNotNull(ContractRegistry::contractFor('pkg::widget'));
        $this->assertNotNull(ContractRegistry::contractFor('vendor.pkg.widget'));
    }

    /**
     * A namespace can carry several hint roots where an EARLIER one sits inside the Composer vendor
     * directory (filtered from discovery) and a later, project-local one survives. Laravel still
     * resolves `pkg::widget` to the vendor file, so the surviving root's same-named template must
     * NOT claim the name: claiming it would mark the local file as covered by references that never
     * reach it, and check callers against a contract Laravel never renders. The local template is
     * still discovered and analyzed — it just owns no view name.
     */
    #[Test]
    public function a_hint_template_shadowed_by_a_filtered_vendor_root_does_not_claim_the_name(): void
    {
        $fakeVendor = $this->root . '/fake-vendor';
        $vendorPkgViews = $fakeVendor . '/acme/pkg/views';
        \mkdir($vendorPkgViews, 0o777, true);
        \file_put_contents($vendorPkgViews . '/widget.blade.php', "<p>vendor</p>\n");

        $localDir = $this->root . '/extra-views';
        \mkdir($localDir, 0o777, true);
        $local = $localDir . '/widget.blade.php';
        \file_put_contents($local, "<p>{{ \$name }}</p>\n");
        $local = (string) \realpath($local);

        $finder = new FileViewFinder(new Filesystem(), [$this->viewDir]);
        $finder->addNamespace('pkg', [$vendorPkgViews, $localDir]);

        $app = new Container();
        $app->instance('blade.compiler', new BladeCompiler(new Filesystem(), $this->root . '/compiled'));
        $app->instance('view.finder', $finder);

        $registrar = new RecordingShadowRegistrar();
        (new BladeBootstrapper($app, $registrar, $this->progress, $this->shadowDir, vendorDirOverride: $fakeVendor))->boot();

        $this->assertNull(ContractRegistry::contractFor('pkg::widget'), 'the filtered vendor root still owns the name');
        $this->assertSame([$local], $registrar->reportableTemplates, 'the local template is still analyzed');
    }

    /**
     * #1552: the same physical view root reaching the finder twice under different case (a
     * published-override-style hint vs. its default-root spelling, or two roots a project
     * configured redundantly) must collapse to ONE discovered template, not double it. `realpath()`
     * cannot do this alone — it preserves the caller's casing on a case-insensitive filesystem — so
     * this only exercises the fix, {@see \Psalm\LaravelPlugin\Internal\PathCaseCanonicalizer}, when
     * the machine running the suite actually has one.
     */
    #[Test]
    public function two_case_variant_spellings_of_the_same_view_root_collapse_into_one_shadow(): void
    {
        if (!$this->filesystemIsCaseInsensitive()) {
            $this->markTestSkipped('needs a case-insensitive filesystem to open one directory under two spellings');
        }

        $casedDir = $this->root . '/Resources/views';
        \mkdir($casedDir, 0o777, true);
        $template = $casedDir . '/widget.blade.php';
        \file_put_contents($template, "<p>{{ \$name }}</p>\n");
        $template = (string) \realpath($template);

        $lowerSpelling = $this->root . '/resources/views';

        $finder = new FileViewFinder(new Filesystem(), [$casedDir, $lowerSpelling]);

        $app = new Container();
        $app->instance('blade.compiler', new BladeCompiler(new Filesystem(), $this->root . '/compiled'));
        $app->instance('view.finder', $finder);

        $registrar = new RecordingShadowRegistrar();
        $this->bootstrapper($app, $registrar)->boot();

        $this->assertSame([], $this->progress->warnings, $this->progress->warningText());
        $this->assertSame(
            [$template],
            $registrar->reportableTemplates,
            'the two spellings of one physical root must not double the discovered templates',
        );
        $this->assertCount(1, $registrar->analyzedShadows, 'one physical template must produce exactly one shadow');
    }

    /**
     * The mirror of the test above: on a case-SENSITIVE filesystem, two directories differing only
     * by case are two real, distinct dirents holding two real, distinct templates — collapsing them
     * would be a regression, not a fix.
     */
    #[Test]
    public function two_distinct_dirents_differing_only_by_case_stay_two_separate_shadows(): void
    {
        if ($this->filesystemIsCaseInsensitive()) {
            $this->markTestSkipped('needs a case-sensitive filesystem to hold two same-named-but-cased dirents');
        }

        $upperDir = $this->root . '/Resources/views';
        $lowerDir = $this->root . '/resources/views';
        \mkdir($upperDir, 0o777, true);
        \mkdir($lowerDir, 0o777, true);
        \file_put_contents($upperDir . '/widget.blade.php', "<p>upper</p>\n");
        \file_put_contents($lowerDir . '/widget.blade.php', "<p>lower</p>\n");

        $finder = new FileViewFinder(new Filesystem(), [$upperDir, $lowerDir]);

        $app = new Container();
        $app->instance('blade.compiler', new BladeCompiler(new Filesystem(), $this->root . '/compiled'));
        $app->instance('view.finder', $finder);

        $registrar = new RecordingShadowRegistrar();
        $this->bootstrapper($app, $registrar)->boot();

        $this->assertSame([], $this->progress->warnings, $this->progress->warningText());
        $this->assertCount(2, $registrar->analyzedShadows, 'two physically distinct templates must stay two shadows');
    }

    /**
     * `realpath()` expands a symlink using the link's STORED target string, so a link whose target
     * is spelled in the wrong case re-introduces the mis-cased spelling AFTER any canonicalization
     * done on the link path itself. `FileViewFinder` realpaths its `getPaths()` entries but stores
     * namespace hints RAW, so the hint layer is where such a link actually reaches discovery. The
     * root list has to be canonicalized after resolution too, or the linked spelling and the
     * direct spelling survive as two roots.
     */
    #[Test]
    public function a_hint_symlink_whose_stored_target_is_mis_cased_still_collapses_with_the_direct_root(): void
    {
        if (!$this->filesystemIsCaseInsensitive()) {
            $this->markTestSkipped('needs a case-insensitive filesystem to open a mis-cased symlink target');
        }

        $casedDir = $this->root . '/Resources/views';
        \mkdir($casedDir, 0o777, true);
        $template = $casedDir . '/widget.blade.php';
        \file_put_contents($template, "<p>{{ \$name }}</p>\n");
        $template = (string) \realpath($template);

        // The stored target string deliberately mis-cases the real 'Resources' dirent.
        \symlink($this->root . '/resources', $this->root . '/link');

        $finder = new FileViewFinder(new Filesystem(), [$casedDir]);
        $finder->addNamespace('pkg', $this->root . '/link/views');

        $app = new Container();
        $app->instance('blade.compiler', new BladeCompiler(new Filesystem(), $this->root . '/compiled'));
        $app->instance('view.finder', $finder);

        $registrar = new RecordingShadowRegistrar();
        $this->bootstrapper($app, $registrar)->boot();

        $this->assertSame([], $this->progress->warnings, $this->progress->warningText());
        $this->assertSame(
            [$template],
            $registrar->reportableTemplates,
            'the linked and the direct spelling of one physical root must not double the discovered templates',
        );
        $this->assertCount(1, $registrar->analyzedShadows, 'one physical template must produce exactly one shadow');
    }

    /**
     * The hint layer is the entry the issue describes (different hint sources supplying different
     * casing): a namespace hint spelled in the wrong case must collapse with the identically-cased
     * default root, not double every template under it.
     */
    #[Test]
    public function a_mis_cased_namespace_hint_collapses_with_the_default_root(): void
    {
        if (!$this->filesystemIsCaseInsensitive()) {
            $this->markTestSkipped('needs a case-insensitive filesystem to open one directory under two spellings');
        }

        $casedDir = $this->root . '/Resources/views';
        \mkdir($casedDir, 0o777, true);
        $template = $casedDir . '/widget.blade.php';
        \file_put_contents($template, "<p>{{ \$name }}</p>\n");
        $template = (string) \realpath($template);

        $finder = new FileViewFinder(new Filesystem(), [$casedDir]);
        $finder->addNamespace('pkg', $this->root . '/resources/views');

        $app = new Container();
        $app->instance('blade.compiler', new BladeCompiler(new Filesystem(), $this->root . '/compiled'));
        $app->instance('view.finder', $finder);

        $registrar = new RecordingShadowRegistrar();
        $this->bootstrapper($app, $registrar)->boot();

        $this->assertSame([], $this->progress->warnings, $this->progress->warningText());
        $this->assertSame(
            [$template],
            $registrar->reportableTemplates,
            'a mis-cased hint spelling of the default root must not double the discovered templates',
        );
        $this->assertCount(1, $registrar->analyzedShadows, 'one physical template must produce exactly one shadow');
    }

    /**
     * A boot can bind 'view' with a closure that needs runtime-only state and throws under the
     * plugin's partial boot, while still binding a perfectly good 'view.finder'. The throw must
     * fall through to the fallback, not disable Blade analysis for the run.
     */
    #[Test]
    public function a_throwing_view_binding_falls_back_to_the_view_finder_binding(): void
    {
        $template = $this->writeTemplate('profile.blade.php', "<p>{{ \$name }}</p>\n");

        $app = new Container();
        $app->instance('blade.compiler', new BladeCompiler(new Filesystem(), $this->root . '/compiled'));
        $app->bind('view', static function (): never {
            throw new \RuntimeException('needs runtime-only state');
        });
        $app->instance('view.finder', new FileViewFinder(new Filesystem(), [$this->viewDir]));

        $registrar = new RecordingShadowRegistrar();

        $this->bootstrapper($app, $registrar)->boot();

        $this->assertSame([], $this->progress->warnings, $this->progress->warningText());
        $this->assertSame([$template], $registrar->reportableTemplates);
    }

    #[Test]
    public function queues_the_ambient_prelude_classes_for_scanning_on_a_fresh_compile(): void
    {
        $this->writeTemplate('profile.blade.php', "<p>{{ \$name }}</p>\n");
        $registrar = new RecordingShadowRegistrar();

        $this->bootstrapper($this->app(), $registrar)->boot();

        $this->assertSame(PreludeBuilder::ambientClassNames(), $registrar->queuedClassLikes);
        // Confirm, not assume: this plain template's shadow carries no string literal at all, so it
        // must not be mistaken for a (vacuous) pass on the #1505 wiring below.
        $this->assertSame([], $registrar->queuedResolvableClassLikes);
    }

    #[Test]
    public function queues_the_ambient_prelude_classes_for_scanning_on_a_warm_manifest_run(): void
    {
        $this->writeTemplate('profile.blade.php', "<p>{{ \$name }}</p>\n");
        $this->bootstrapper($this->app(), new RecordingShadowRegistrar())->boot();

        // Second run against the same shadow dir: every template is a manifest freshness hit, so
        // ShadowCompiler never runs, and the queueing still has to happen (see BladeBootstrapper::run()).
        $second = new RecordingShadowRegistrar();
        $this->bootstrapper($this->app(), $second)->boot();

        $this->assertSame(PreludeBuilder::ambientClassNames(), $second->queuedClassLikes);
        $this->assertSame([], $second->queuedResolvableClassLikes);
    }

    /**
     * #1505: a compiled shadow's PHP string literal naming a class (the shape a vendor directive
     * that writes `app('Vendor\Package\Class')::method()` compiles to) must be handed to the
     * registrar's speculative-queueing method, separate from the ambient list above.
     */
    #[Test]
    public function literal_named_classes_in_a_compiled_shadow_are_queued_for_scanning_on_a_fresh_compile(): void
    {
        $this->writeTemplate('widget.blade.php', "<p>{{ \$name }}</p>\n@php\necho 'Vendor\\Package\\Widget';\n@endphp\n");
        $registrar = new RecordingShadowRegistrar();

        $this->bootstrapper($this->app(), $registrar)->boot();

        $this->assertSame(['Vendor\Package\Widget'], $registrar->queuedResolvableClassLikes);
    }

    /**
     * Same shape, second run: `ShadowCompiler` never runs on a warm manifest hit (see
     * `BladeBootstrapper::compileAll()`'s `isFresh()` branch), so the extraction must come off the
     * shadow FILE on disk rather than the fresh compile result the first run produced.
     */
    #[Test]
    public function literal_named_classes_are_queued_on_a_warm_manifest_run_too(): void
    {
        $this->writeTemplate('widget.blade.php', "<p>{{ \$name }}</p>\n@php\necho 'Vendor\\Package\\Widget';\n@endphp\n");
        $this->bootstrapper($this->app(), new RecordingShadowRegistrar())->boot();

        $second = new RecordingShadowRegistrar();
        $this->bootstrapper($this->app(), $second)->boot();

        $this->assertSame(['Vendor\Package\Widget'], $second->queuedResolvableClassLikes);
    }

    #[Test]
    public function finds_templates_in_nested_directories_and_ignores_other_files(): void
    {
        $nested = $this->writeTemplate('mail/welcome.blade.php', "{{ \$subject }}\n");
        $this->writeTemplate('notes.txt', 'not a template');
        $this->writeTemplate('legacy.php', '<?php echo 1;');
        $registrar = new RecordingShadowRegistrar();

        $this->bootstrapper($this->app(), $registrar)->boot();

        $this->assertSame([$nested], $registrar->reportableTemplates);
    }

    #[Test]
    public function an_unchanged_template_is_not_recompiled_but_is_still_registered(): void
    {
        $template = $this->writeTemplate('profile.blade.php', "<p>{{ \$name }}</p>\n");
        $first = new RecordingShadowRegistrar();
        $this->bootstrapper($this->app(), $first)->boot();

        // A marker only a recompile would overwrite.
        $shadow = $first->analyzedShadows[0];
        \file_put_contents($shadow, "<?php // reused\n");

        $second = new RecordingShadowRegistrar();
        $this->bootstrapper($this->app(), $second)->boot();

        $this->assertSame("<?php // reused\n", (string) \file_get_contents($shadow));
        $this->assertSame([$template], $second->reportableTemplates);
        $this->assertSame([$shadow], $second->analyzedShadows);
    }

    #[Test]
    public function an_edited_template_is_recompiled(): void
    {
        $this->writeTemplate('profile.blade.php', "<p>{{ \$name }}</p>\n");
        $first = new RecordingShadowRegistrar();
        $this->bootstrapper($this->app(), $first)->boot();

        $this->writeTemplate('profile.blade.php', "<p>{{ \$title }}</p>\n");
        $second = new RecordingShadowRegistrar();
        $this->bootstrapper($this->app(), $second)->boot();

        $this->assertStringContainsString('echo e($title)', (string) \file_get_contents($second->analyzedShadows[0]));
    }

    #[Test]
    public function a_shadow_whose_template_disappeared_is_pruned(): void
    {
        $template = $this->writeTemplate('gone.blade.php', "{{ \$x }}\n");
        $first = new RecordingShadowRegistrar();
        $this->bootstrapper($this->app(), $first)->boot();
        $shadow = $first->analyzedShadows[0];

        \unlink($template);
        $this->writeTemplate('kept.blade.php', "{{ \$y }}\n");
        $second = new RecordingShadowRegistrar();
        $this->bootstrapper($this->app(), $second)->boot();

        $this->assertFileDoesNotExist($shadow);
        $this->assertCount(1, $second->analyzedShadows);
    }

    #[Test]
    public function a_directory_scan_failure_does_not_prune_a_shadow_it_could_not_rediscover(): void
    {
        $nested = $this->viewDir . '/protected';
        \mkdir($nested, 0o777, true);
        $this->writeTemplate('protected/still-here.blade.php', "{{ \$x }}\n");
        $first = new RecordingShadowRegistrar();
        $this->bootstrapper($this->app(), $first)->boot();
        $shadow = $first->analyzedShadows[0];
        $this->assertFileExists($shadow);

        // The template is untouched, but a transient permission failure hides it from this
        // run's scan: findTemplates() cannot tell that apart from the template being gone, so
        // it must record a failure instead of silently returning an incomplete list.
        \chmod($nested, 0o000);

        try {
            $second = new RecordingShadowRegistrar();
            $this->bootstrapper($this->app(), $second)->boot();

            $this->assertFileExists($shadow, 'a transient scan failure must not prune a shadow it never confirmed as gone');
            $this->assertStringContainsString('Blade template(s) were skipped', $this->progress->warningText());
            $this->assertStringContainsString('could not be read', \implode("\n", $this->progress->debugMessages));
        } finally {
            \chmod($nested, 0o777);
        }
    }

    #[Test]
    public function degrades_when_the_blade_compiler_is_unbound(): void
    {
        $app = new Container();
        $app->instance('view.finder', new FileViewFinder(new Filesystem(), [$this->viewDir]));
        $this->writeTemplate('profile.blade.php', "{{ \$name }}\n");
        $registrar = new RecordingShadowRegistrar();

        $this->bootstrapper($app, $registrar)->boot();

        $this->assertCount(1, $this->progress->warnings);
        $this->assertStringContainsString('blade.compiler', $this->progress->warningText());
        $this->assertSame(0, $registrar->markCalls);
        $this->assertSame([], $registrar->analyzedShadows);
        $this->assertDirectoryDoesNotExist($this->shadowDir);
    }

    #[Test]
    public function degrades_when_the_blade_compiler_binding_is_not_a_blade_compiler(): void
    {
        $app = $this->app();
        $app->instance('blade.compiler', new \stdClass());
        $this->writeTemplate('profile.blade.php', "{{ \$name }}\n");
        $registrar = new RecordingShadowRegistrar();

        $this->bootstrapper($app, $registrar)->boot();

        $this->assertCount(1, $this->progress->warnings);
        $this->assertStringContainsString('blade.compiler', $this->progress->warningText());
        $this->assertSame([], $registrar->analyzedShadows);
    }

    #[Test]
    public function degrades_when_the_view_finder_is_unbound(): void
    {
        $app = new Container();
        $app->instance('blade.compiler', new BladeCompiler(new Filesystem(), $this->root . '/compiled'));

        $registrar = new RecordingShadowRegistrar();

        $this->bootstrapper($app, $registrar)->boot();

        $this->assertCount(1, $this->progress->warnings);
        $this->assertStringContainsString('view', $this->progress->warningText());
        $this->assertSame([], $registrar->analyzedShadows);
    }

    #[Test]
    public function falls_back_to_the_view_factory_finder_when_view_finder_is_unbound(): void
    {
        $template = $this->writeTemplate('profile.blade.php', "{{ \$name }}\n");
        $app = new Container();
        $app->instance('blade.compiler', new BladeCompiler(new Filesystem(), $this->root . '/compiled'));

        $factory = $this->createStub(\Illuminate\View\Factory::class);
        $factory->method('getFinder')->willReturn(new FileViewFinder(new Filesystem(), [$this->viewDir]));
        $app->instance('view', $factory);
        $registrar = new RecordingShadowRegistrar();

        $this->bootstrapper($app, $registrar)->boot();

        $this->assertSame([], $this->progress->warnings, $this->progress->warningText());
        $this->assertSame([$template], $registrar->reportableTemplates);
    }

    #[Test]
    public function degrades_when_the_cache_directory_cannot_be_created(): void
    {
        $this->writeTemplate('profile.blade.php', "{{ \$name }}\n");
        // A regular file where the shadow directory should go: mkdir cannot win.
        \mkdir(\dirname($this->shadowDir), 0o777, true);
        \file_put_contents($this->shadowDir, 'in the way');
        $registrar = new RecordingShadowRegistrar();

        $this->bootstrapper($this->app(), $registrar)->boot();

        $this->assertCount(1, $this->progress->warnings);
        $this->assertStringContainsString($this->shadowDir, $this->progress->warningText());
        $this->assertSame(0, $registrar->markCalls);
    }

    #[Test]
    public function degrades_when_the_cache_directory_is_not_writable(): void
    {
        $this->writeTemplate('profile.blade.php', "{{ \$name }}\n");
        \mkdir(\dirname($this->shadowDir), 0o777, true);
        \mkdir($this->shadowDir, 0o555);
        $registrar = new RecordingShadowRegistrar();

        $this->bootstrapper($this->app(), $registrar)->boot();

        \chmod($this->shadowDir, 0o777);

        $this->assertCount(1, $this->progress->warnings);
        $this->assertStringContainsString('writable', $this->progress->warningText());
        $this->assertSame(0, $registrar->markCalls);
    }

    #[Test]
    public function reports_a_discovery_failure_even_when_the_shadow_directory_cannot_be_prepared(): void
    {
        $nested = $this->viewDir . '/protected';
        \mkdir($nested, 0o777, true);
        $this->writeTemplate('protected/unreachable.blade.php', "{{ \$x }}\n");
        \chmod($nested, 0o000);

        // A regular file where the shadow directory should go: mkdir cannot win. This failure
        // happens after findTemplates() has already recorded the scan failure above, so both
        // causes must reach the user even though the run bails out early.
        \mkdir(\dirname($this->shadowDir), 0o777, true);
        \file_put_contents($this->shadowDir, 'in the way');
        $registrar = new RecordingShadowRegistrar();

        try {
            $this->bootstrapper($this->app(), $registrar)->boot();
        } finally {
            \chmod($nested, 0o777);
        }

        $this->assertCount(2, $this->progress->warnings, $this->progress->warningText());
        $this->assertStringContainsString($this->shadowDir, $this->progress->warningText());
        $this->assertStringContainsString('Blade template(s) were skipped', $this->progress->warningText());
    }

    #[Test]
    public function a_template_that_fails_to_compile_is_reported_once_and_the_rest_still_register(): void
    {
        $broken = $this->writeTemplate('broken.blade.php', "{{ \$x }}\n@unparseable\n");
        $healthy = $this->writeTemplate('healthy.blade.php', "{{ \$y }}\n");
        $registrar = new RecordingShadowRegistrar();

        // Compile failure is a Throwable out of the Blade compiler; force one deterministically.
        $app = $this->app();
        $app->instance('blade.compiler', new ThrowingBladeCompiler(new Filesystem(), $this->root . '/compiled', '@unparseable'));

        $this->bootstrapper($app, $registrar)->boot();

        // Two warnings total, not three: one because `ThrowingBladeCompiler` is a compiler
        // SUBCLASS (unconditionally untrustworthy, #1517 F3, unrelated to this test's own point),
        // and one aggregated compile-failure warning for the broken template — never one per
        // failed template.
        $this->assertCount(2, $this->progress->warnings, $this->progress->warningText());
        $this->assertStringContainsString('broken.blade.php', $this->progress->warningText());
        // Every discovered template is reportable, not only the ones that compiled: UnusedView has
        // to be able to report on a template that failed to compile too (#1477).
        $this->assertSame([$broken, $healthy], $registrar->reportableTemplates);
        $this->assertCount(1, $registrar->analyzedShadows);
    }

    /**
     * A template that fails to compile has unknown @include/@extends references, not empty ones:
     * treating them as empty would cascade into false UnusedView positives on everything it renders.
     *
     * The healthy sibling is what carries the run to activation: publication is deferred to a
     * successful boot (#1518), and the dynamic mark is a SAFETY signal, so it is the one fact that
     * must survive the mixed success/failure path rather than being dropped with the failed template.
     */
    #[Test]
    public function a_template_that_fails_to_compile_marks_the_reference_set_dynamic(): void
    {
        $this->writeTemplate('broken.blade.php', "{{ \$x }}\n@unparseable\n");
        $this->writeTemplate('healthy.blade.php', "{{ \$y }}\n");

        $app = $this->app();
        $app->instance('blade.compiler', new ThrowingBladeCompiler(new Filesystem(), $this->root . '/compiled', '@unparseable'));

        $this->bootstrapper($app, new RecordingShadowRegistrar())->boot();

        $this->assertTrue(ViewReferenceRegistry::isDynamic());
    }

    #[Test]
    public function registers_nothing_when_the_project_file_write_fails(): void
    {
        $this->writeTemplate('profile.blade.php', "{{ \$name }}\n");
        $registrar = new RecordingShadowRegistrar(markSucceeds: false);

        $this->bootstrapper($this->app(), $registrar)->boot();

        $this->assertSame(1, $registrar->markCalls);
        $this->assertSame([], $registrar->analyzedShadows, 'an invisible shadow is pure cost: register nothing');
        $this->assertCount(1, $this->progress->warnings);
    }

    /**
     * Degradation is all-or-nothing (#1518). ContractRegistry and ViewReferenceRegistry CREATE
     * issues at call sites and on templates, so a run whose templates never became reportable must
     * leave both empty — otherwise the contract rules keep firing against a feature that announced
     * itself disabled, and UnusedView reports templates whose references were never collected.
     */
    #[Test]
    public function publishes_no_template_facts_when_activation_fails(): void
    {
        // The dynamic include and the enabled collection pass are what give the isDynamic()
        // assertion below teeth: without them nothing in the fixture could set the flag either way.
        $this->writeTemplate('profile.blade.php', "@include(\$partial)\n{{ \$name }}\n");
        $registrar = new RecordingShadowRegistrar(markSucceeds: false);

        $booted = $this->collectingBootstrapper($this->app(), $registrar)->boot();

        $this->assertFalse($booted);
        $this->assertNull(ContractRegistry::contractFor('profile'), 'a contract nothing can remap must not reach call sites');
        $this->assertSame([], ViewReferenceRegistry::unusedTemplates());
        $this->assertFalse(ViewReferenceRegistry::isDynamic(), 'the dynamic mark is a published fact too');

        $shadows = $this->shadowFiles();
        $this->assertCount(1, $shadows, 'the shadow was written to disk; only its publication is withheld');

        foreach ($shadows as $shadow) {
            $this->assertNull(ShadowRegistry::entryFor($shadow));
        }

        $this->assertCount(1, $this->progress->warnings, $this->progress->warningText());
    }

    /**
     * `123.blade.php` is a legal view name. `ViewReferenceCollector` hands its names back through
     * `array_keys()`, and PHP casts a numeric-string key to int on the way in, so the name reaches
     * the `string`-typed registry as an int and throws under strict_types. Buffered publication
     * makes that worse than it was: the throw now lands after the shadows are already enqueued,
     * leaving exactly the half-activated run #1518 exists to prevent.
     */
    #[Test]
    public function a_numeric_view_name_does_not_break_publication(): void
    {
        $this->writeTemplate('dashboard.blade.php', "@include('123')\n");
        $numeric = $this->writeTemplate('123.blade.php', "<p>ok</p>\n");
        $registrar = new RecordingShadowRegistrar();

        $booted = $this->collectingBootstrapper($this->app(), $registrar)->boot();

        $this->assertTrue($booted, $this->progress->warningText());
        $this->assertSame([], $this->progress->warnings, $this->progress->warningText());
        $this->assertArrayNotHasKey('123', ViewReferenceRegistry::unusedTemplates(), 'the @include is a reference');
        $this->assertNotNull(ContractRegistry::contractFor('123'));
        $this->assertFileExists($numeric);
    }

    /**
     * A manifest written before the cast above still holds integer names, and its entries stay fresh
     * across dev builds (the fingerprint carries the plugin's Composer version, which does not move
     * between commits on a branch install). `ShadowManifest::normalizeViewNames()` is what keeps
     * those out of the replay: it drops the whole entry rather than hand a non-string name on, which
     * costs one recompile. Pinned because that rejection is the only thing standing between a stale
     * manifest and the TypeError above, and it is not obvious from the bootstrapper.
     */
    #[Test]
    public function a_numeric_view_name_loaded_from_an_older_manifest_does_not_break_publication(): void
    {
        $this->writeTemplate('dashboard.blade.php', "@include('123')\n");
        $this->writeTemplate('123.blade.php', "<p>ok</p>\n");

        $this->collectingBootstrapper($this->app(), new RecordingShadowRegistrar())->boot();
        $this->downgradeManifestReferenceNamesToIntegers();

        ViewReferenceRegistry::reset();
        ContractRegistry::reset();
        $this->progress = new RecordingProgress();
        $registrar = new RecordingShadowRegistrar();

        $booted = $this->collectingBootstrapper($this->app(), $registrar)->boot();

        $this->assertTrue($booted, $this->progress->warningText());
        $this->assertSame([], $this->progress->warnings, $this->progress->warningText());
        $this->assertArrayNotHasKey('123', ViewReferenceRegistry::unusedTemplates());
    }

    /** Rewrites every stored reference name as the int a pre-fix build would have written. */
    private function downgradeManifestReferenceNamesToIntegers(): void
    {
        $path = $this->shadowDir . '/manifest.php';
        /** @var array<string, array<int, mixed>> $entries */
        $entries = include $path;

        foreach ($entries as $shadowPath => $entry) {
            if (!\is_array($entry[6] ?? null) || !\is_array($entry[6][0])) {
                continue;
            }

            $entries[$shadowPath][6][0] = \array_keys(\array_fill_keys($entry[6][0], true));
        }

        \file_put_contents($path, "<?php\n\nreturn " . \var_export($entries, true) . ";\n");
    }

    private function collectingBootstrapper(Container $app, RecordingShadowRegistrar $registrar): BladeBootstrapper
    {
        return new BladeBootstrapper($app, $registrar, $this->progress, $this->shadowDir, collectViewReferences: true);
    }

    /** The reorder that makes the failure path empty must still publish everything on success. */
    #[Test]
    public function publishes_every_registry_when_activation_succeeds(): void
    {
        $template = $this->writeTemplate('profile.blade.php', "{{ \$name }}\n");
        $registrar = new RecordingShadowRegistrar();

        $this->bootstrapper($this->app(), $registrar)->boot();

        $this->assertNotNull(ContractRegistry::contractFor('profile'));
        $this->assertSame(['profile' => $template], ViewReferenceRegistry::unusedTemplates());
        $this->assertCount(1, $registrar->analyzedShadows);
        $this->assertNotNull(ShadowRegistry::entryFor($registrar->analyzedShadows[0]));
    }

    /**
     * Shadow files written this run, straight off disk: on the failure path the registrar never
     * receives them, so the bootstrapper's own return value cannot name them.
     *
     * @return list<string>
     */
    private function shadowFiles(): array
    {
        $shadows = [];

        foreach (\glob($this->shadowDir . '/*.php') ?: [] as $path) {
            if (\basename($path) !== 'manifest.php') {
                $shadows[] = $path;
            }
        }

        return $shadows;
    }

    #[Test]
    public function warns_once_when_no_templates_are_discovered(): void
    {
        $registrar = new RecordingShadowRegistrar();

        $this->bootstrapper($this->app(), $registrar)->boot();

        $this->assertCount(1, $this->progress->warnings);
        $this->assertStringContainsString('no Blade templates were discovered', $this->progress->warningText());
        $this->assertSame(0, $registrar->markCalls);
    }

    #[Test]
    public function a_missing_view_directory_warns_once_but_is_not_a_failure(): void
    {
        $app = new Container();
        $app->instance('blade.compiler', new BladeCompiler(new Filesystem(), $this->root . '/compiled'));
        $app->instance('view.finder', new FileViewFinder(new Filesystem(), [$this->root . '/nowhere']));

        $registrar = new RecordingShadowRegistrar();

        $this->bootstrapper($app, $registrar)->boot();

        $this->assertCount(1, $this->progress->warnings);
        $this->assertStringContainsString('no Blade templates were discovered', $this->progress->warningText());
        $this->assertSame(0, $registrar->markCalls);
    }

    /**
     * #1517: a custom directive's handler is a closure loaded from a separate PHP file. The
     * template that uses the directive never changes, but the directive's own implementation
     * does — the fingerprint has to fold in the compiler environment, not just the template
     * source, or the second run reuses the first run's (now stale) shadow.
     */
    #[Test]
    public function a_custom_directive_swapped_between_runs_recompiles_even_though_the_template_did_not_change(): void
    {
        $directiveFile = $this->root . '/directive.php';
        \file_put_contents($directiveFile, "<?php\nreturn static function (\$expression) { return '<?php echo \"MARK-V1\"; ?>'; };\n");
        $template = $this->writeTemplate('uses-directive.blade.php', "@marker\n");

        $app = $this->app();
        /** @var BladeCompiler $compiler */
        $compiler = $app->make('blade.compiler');
        /** @var callable $handler */
        $handler = include $directiveFile;
        $compiler->directive('marker', $handler);

        $first = new RecordingShadowRegistrar();
        $this->bootstrapper($app, $first)->boot();
        $this->assertSame([], $this->progress->warnings, $this->progress->warningText());
        $this->assertStringContainsString('MARK-V1', (string) \file_get_contents($first->analyzedShadows[0]));

        \file_put_contents($directiveFile, "<?php\nreturn static function (\$expression) { return '<?php echo \"MARK-V2\"; ?>'; };\n");

        $secondApp = $this->app();
        /** @var BladeCompiler $secondCompiler */
        $secondCompiler = $secondApp->make('blade.compiler');
        /** @var callable $secondHandler */
        $secondHandler = include $directiveFile;
        $secondCompiler->directive('marker', $secondHandler);

        $second = new RecordingShadowRegistrar();
        $this->bootstrapper($secondApp, $second)->boot();

        $this->assertSame([$template], $second->reportableTemplates);
        $this->assertStringContainsString(
            'MARK-V2',
            (string) \file_get_contents($second->analyzedShadows[0]),
            'a directive edited between runs must recompile the shadow even though the template itself is unchanged',
        );
    }

    /**
     * The compiler-environment hash must be stable across two runs that register the exact same
     * directive: otherwise every project with even one custom directive would recompile on every
     * single run and the freshness cache would never hit.
     */
    #[Test]
    public function the_same_custom_directive_registered_twice_reuses_the_same_shadow(): void
    {
        $this->writeTemplate('uses-directive.blade.php', "@marker\n");

        $registerMarker = function (Container $app): void {
            /** @var BladeCompiler $compiler */
            $compiler = $app->make('blade.compiler');
            $compiler->directive('marker', static fn(string $expression): string => '<?php echo "MARK"; ?>');
        };

        $firstApp = $this->app();
        $registerMarker($firstApp);
        $first = new RecordingShadowRegistrar();
        $this->bootstrapper($firstApp, $first)->boot();
        $shadow = $first->analyzedShadows[0];
        \file_put_contents($shadow, "<?php // reused\n");

        $secondApp = $this->app();
        $registerMarker($secondApp);
        $second = new RecordingShadowRegistrar();
        $this->bootstrapper($secondApp, $second)->boot();

        $this->assertSame([$shadow], $second->analyzedShadows, 'the same environment must resolve to the same shadow path');
        $this->assertSame("<?php // reused\n", (string) \file_get_contents($shadow), 'a fresh hit must not rewrite the shadow');
    }

    /**
     * #1517 M1: an invokable object's constructor state is invisible to
     * `ReflectionFunction::getStaticVariables()`, so two directive handlers that are different
     * OBJECT INSTANCES with different behaviour must still force a recompile between runs, not
     * just hash identically off their shared class file and go stale.
     */
    #[Test]
    public function an_invokable_directive_object_swapped_between_runs_recompiles_even_though_the_template_did_not_change(): void
    {
        $template = $this->writeTemplate('uses-directive.blade.php', "@marker\n");

        $firstApp = $this->app();
        /** @var BladeCompiler $firstCompiler */
        $firstCompiler = $firstApp->make('blade.compiler');
        $firstCompiler->directive('marker', new MarkerDirective('MARK-V1'));

        $first = new RecordingShadowRegistrar();
        $this->bootstrapper($firstApp, $first)->boot();
        $this->assertStringContainsString('MARK-V1', (string) \file_get_contents($first->analyzedShadows[0]));

        $secondApp = $this->app();
        /** @var BladeCompiler $secondCompiler */
        $secondCompiler = $secondApp->make('blade.compiler');
        $secondCompiler->directive('marker', new MarkerDirective('MARK-V2'));

        $second = new RecordingShadowRegistrar();
        $this->bootstrapper($secondApp, $second)->boot();

        $this->assertSame([$template], $second->reportableTemplates);
        $this->assertStringContainsString(
            'MARK-V2',
            (string) \file_get_contents($second->analyzedShadows[0]),
            'an invokable directive object swapped between runs must recompile even though its class file did not change',
        );
    }

    /**
     * `if()` stores the user's callback in the compiler's `$conditions` array, distinct from the
     * wrapper directives it also registers (which live in `$customDirectives` and never change).
     * Editing only the condition callback must still invalidate the cache, even though nothing in
     * `getCustomDirectives()` or the template moved.
     */
    #[Test]
    public function editing_a_blade_if_condition_callback_invalidates_the_cache(): void
    {
        $conditionFile = $this->root . '/condition.php';
        \file_put_contents($conditionFile, "<?php\nreturn static fn (): bool => true;\n");
        $this->writeTemplate('uses-condition.blade.php', "@disco\nyes\n@endisco\n");

        $firstApp = $this->app();
        /** @var BladeCompiler $firstCompiler */
        $firstCompiler = $firstApp->make('blade.compiler');
        /** @var callable(): bool $firstCallback */
        $firstCallback = include $conditionFile;
        $firstCompiler->if('disco', $firstCallback);

        $first = new RecordingShadowRegistrar();
        $this->bootstrapper($firstApp, $first)->boot();

        \file_put_contents($conditionFile, "<?php\nreturn static fn (): bool => false;\n");

        $secondApp = $this->app();
        /** @var BladeCompiler $secondCompiler */
        $secondCompiler = $secondApp->make('blade.compiler');
        /** @var callable(): bool $secondCallback */
        $secondCallback = include $conditionFile;
        $secondCompiler->if('disco', $secondCallback);

        $second = new RecordingShadowRegistrar();
        $this->bootstrapper($secondApp, $second)->boot();

        $this->assertNotSame(
            $first->analyzedShadows[0],
            $second->analyzedShadows[0],
            'an edited condition callback must produce a different shadow path even though the template did not change',
        );
    }

    /**
     * #1517 M3: positive-side coverage for the `$trustedEnvironment && $manifest->isFresh(...)`
     * gate (`BladeBootstrapper::compileAll()`). An internal-function directive handler
     * (`getFileName()` returns `false`) makes `CompilerEnvironment::describe()` report
     * `trustworthy = false`, which must (a) emit the untrusted-environment warning every run, and
     * (b) force a recompile even on a SECOND run against an unchanged template and an unchanged
     * (still-untrustworthy) environment — the existing coverage only pins the negative side
     * (`assertSame([], $this->progress->warnings)` elsewhere in this suite).
     */
    #[Test]
    public function an_untrustworthy_environment_recompiles_every_run_and_warns(): void
    {
        $registerUntrustedDirective = static function (Container $app): void {
            /** @var BladeCompiler $compiler */
            $compiler = $app->make('blade.compiler');
            $compiler->directive('shout', 'strtoupper');
        };

        $this->writeTemplate('profile.blade.php', "<p>{{ \$name }}</p>\n");

        $firstApp = $this->app();
        $registerUntrustedDirective($firstApp);
        $first = new RecordingShadowRegistrar();
        $this->bootstrapper($firstApp, $first)->boot();

        $this->assertCount(1, $this->progress->warnings, 'an unresolvable directive must warn even on the first, cold run');
        $this->assertStringContainsString('compiler environment', $this->progress->warningText());

        // A marker only a recompile would overwrite.
        $shadow = $first->analyzedShadows[0];
        \file_put_contents($shadow, "<?php // stale\n");

        $secondApp = $this->app();
        $registerUntrustedDirective($secondApp);
        $second = new RecordingShadowRegistrar();
        $this->bootstrapper($secondApp, $second)->boot();

        $this->assertCount(2, $this->progress->warnings, 'the second run must warn again: the environment is still untrustworthy');
        $this->assertNotSame(
            "<?php // stale\n",
            (string) \file_get_contents($second->analyzedShadows[0]),
            'an untrustworthy environment must force a recompile even though nothing else changed',
        );
    }
}
