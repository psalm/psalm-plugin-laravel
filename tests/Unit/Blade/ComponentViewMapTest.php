<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ComponentViewMap;

#[CoversClass(ComponentViewMap::class)]
final class ComponentViewMapTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @\unlink($file);
        }
    }

    /**
     * Writes one class into its own file and declares it, the way the booted app's autoloader would.
     * Every class gets a namespace unique to the test run, so repeated runs never redeclare one.
     *
     * @return string the file path
     */
    private function componentFile(string $classBody, string $extends = '\Illuminate\View\Component', string $uses = ''): string
    {
        static $counter = 0;
        $namespace = 'ComponentViewMapTest' . \getmypid() . '_' . ++$counter;
        $file = \sys_get_temp_dir() . "/{$namespace}.php";

        \file_put_contents($file, "<?php\nnamespace {$namespace};\n{$uses}\nfinal class Widget extends {$extends}\n{\n{$classBody}\n}\n");
        $this->files[] = $file;

        require $file;

        return $file;
    }

    #[Test]
    public function a_literal_render_maps_its_view_and_keeps_only_keys_data_cannot_supply(): void
    {
        $file = $this->componentFile(<<<'PHP'
                public string $label = 'x';
                public function render() { return view('widgets/card', ['title' => $this->title(), 'label' => 'y', '__hidden' => 1, 'errors' => 2, 'not-a-name' => 3]); }
                protected function title(): string { return 't'; }
            PHP);

        $view = ComponentViewMap::build([$file])->get('widgets.card');

        $this->assertNotNull($view);
        $this->assertSame(['title'], $view['keys']);
        $this->assertStringContainsString("['title' => \$title] = (\$__laravelComponentScope->bind(function () { return ['title' => \$this->title()", $view['scope']);
    }

    #[Test]
    public function the_facade_and_factory_forms_map_too(): void
    {
        $facade = $this->componentFile(
            "public function render() { return View::make('facade', ['a' => 1]); }",
            uses: 'use Illuminate\Support\Facades\View;',
        );
        $factory = $this->componentFile("public function render() { return view()->make('factory', ['a' => 1]); }");

        $map = ComponentViewMap::build([$facade, $factory]);

        $this->assertNotNull($map->get('facade'));
        $this->assertNotNull($map->get('factory'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function declinedRenders(): iterable
    {
        yield 'a local variable' => ["public function render() { \$x = 1; return view('v', ['a' => \$x]); }"];
        yield 'a variable in the array' => ["public function render() { return view('v', ['a' => \$GLOBALS]); }"];
        yield 'a computed view name' => ["public function render() { return view('v' . 'w', ['a' => 1]); }"];
        yield 'a computed array' => ["public function render() { return view('v', \\array_merge(['a' => 1])); }"];
        yield 'a spread' => ["public function render() { return view('v', [...['a' => 1]]); }"];
        yield 'mergeData' => ["public function render() { return view('v', ['a' => 1], ['b' => 2]); }"];
        yield 'a chained with()' => ["public function render() { return view('v', ['a' => 1])->with('b', 2); }"];
        yield 'a scope function' => ["public function render() { return view('v', ['a' => \\compact('b')]); }"];
        yield 'a magic constant' => ["public function render() { return view('v', ['a' => __CLASS__]); }"];
        yield 'a data() override' => ["public function render() { return view('v', ['a' => 1]); }\npublic function data() { return []; }"];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('declinedRenders')]
    public function a_render_outside_the_copyable_shape_declines(string $classBody): void
    {
        $this->assertNull(ComponentViewMap::build([$this->componentFile($classBody)])->get('v'));
    }

    #[Test]
    public function a_class_that_is_not_a_component_declines(): void
    {
        $file = $this->componentFile("public function render() { return view('v', ['a' => 1]); }", '\ArrayObject');

        $this->assertNull(ComponentViewMap::build([$file])->get('v'));
    }

    #[Test]
    public function a_view_two_classes_render_declines_for_both(): void
    {
        $body = "public function render() { return view('shared', ['a' => 1]); }";

        $this->assertNull(ComponentViewMap::build([$this->componentFile($body), $this->componentFile($body)])->get('shared'));
    }

    /** A second renderer outside the copyable shape still passes other data to the view. */
    #[Test]
    public function a_view_another_render_names_outside_the_shape_declines(): void
    {
        $copyable = $this->componentFile("public function render() { return view('shared', ['a' => 'text']); }");

        $this->assertNotNull(ComponentViewMap::build([$copyable])->get('shared'));

        foreach ([
            "public function render() { \$n = 42; return view('shared', ['a' => \$n]); }",
            "public function render() { return \$this->ok() ? view()->make('shared', ['a' => 1]) : View::make('other'); }\nprivate function ok(): bool { return true; }",
        ] as $body) {
            $other = $this->componentFile($body, uses: 'use Illuminate\Support\Facades\View;');

            $this->assertNull(ComponentViewMap::build([$copyable, $other])->get('shared'));
        }
    }
}
