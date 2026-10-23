<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Views\MissingViewHandler;
use Psalm\LaravelPlugin\Plugin;
use Psalm\Progress\Progress;

/**
 * The view paths MissingViewHandler checks existence against, which Blade's `@includeFirst` check
 * also reads with findMissingViews off.
 */
#[CoversClass(Plugin::class)]
final class PluginViewFinderInitTest extends TestCase
{
    private string $viewDir;

    protected function setUp(): void
    {
        MissingViewHandler::reset();
        $this->viewDir = \sys_get_temp_dir() . '/psalm-laravel-finder-' . \str_replace('.', '', \uniqid('', true));
        \mkdir($this->viewDir);
        \file_put_contents($this->viewDir . '/override.blade.php', '');
    }

    protected function tearDown(): void
    {
        MissingViewHandler::reset();
        \unlink($this->viewDir . '/override.blade.php');
        \rmdir($this->viewDir);
    }

    /** A throwing finder binding degrades existence to unknown instead of escaping the boot. */
    #[Test]
    public function a_throwing_finder_binding_leaves_existence_unknown(): void
    {
        $app = new Application($this->viewDir);
        $app->bind('view.finder', static fn(): never => throw new \RuntimeException('needs runtime state'));

        $output = $this->createMock(Progress::class);
        $output->expects($this->never())->method('warning');
        $this->init($app, $output, null, false);
        $this->assertNull(MissingViewHandler::viewExists('override'));

        $output = $this->createMock(Progress::class);
        $output->expects($this->once())->method('warning');
        $this->init($app, $output, null, true);
        $this->assertNull(MissingViewHandler::viewExists('override'));
    }

    /**
     * `view.finder` is bound transiently, so resolving it again loses the locations added on the
     * finder the factory renders with.
     */
    #[Test]
    public function the_factory_finder_wins_over_a_fresh_finder_binding(): void
    {
        $app = new Application($this->viewDir);
        $app->bind('view.finder', static fn(): FileViewFinder => new FileViewFinder(new Filesystem(), []));

        $factory = new Factory(new EngineResolver(), new FileViewFinder(new Filesystem(), []), new Dispatcher());
        $factory->addLocation($this->viewDir);

        $this->init($app, $this->createStub(Progress::class), $factory, false);

        $this->assertTrue(MissingViewHandler::viewExists('override'));
    }

    private function init(Application $app, Progress $output, ?Factory $factory, bool $report): void
    {
        (new \ReflectionMethod(Plugin::class, 'initMissingViewHandler'))->invoke(new Plugin(), $app, $output, $factory, $report);
    }
}
