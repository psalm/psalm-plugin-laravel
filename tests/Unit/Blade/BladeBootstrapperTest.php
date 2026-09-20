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
    }

    protected function tearDown(): void
    {
        $this->deleteRecursively($this->root);
    }

    private function deleteRecursively(string $path): void
    {
        if (\is_file($path)) {
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

        $this->assertCount(1, $this->progress->warnings, 'one aggregated warning, never one per template');
        $this->assertStringContainsString('broken.blade.php', $this->progress->warningText());
        // Every discovered template is reportable, not only the ones that compiled: UnusedView has
        // to be able to report on a template that failed to compile too (#1477).
        $this->assertSame([$broken, $healthy], $registrar->reportableTemplates);
        $this->assertCount(1, $registrar->analyzedShadows);
    }

    /**
     * A template that fails to compile has unknown @include/@extends references, not empty ones:
     * treating them as empty would cascade into false UnusedView positives on everything it renders.
     */
    #[Test]
    public function a_template_that_fails_to_compile_marks_the_reference_set_dynamic(): void
    {
        $this->writeTemplate('broken.blade.php', "{{ \$x }}\n@unparseable\n");

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
}
