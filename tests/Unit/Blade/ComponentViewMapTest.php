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
    private function componentFile(string $classBody, string $extends = '\Illuminate\View\Component', string $uses = '', bool $final = true, bool $declare = true): string
    {
        static $counter = 0;
        $namespace = 'ComponentViewMapTest' . \getmypid() . '_' . ++$counter;
        $file = \sys_get_temp_dir() . "/{$namespace}.php";
        $modifier = $final ? 'final ' : '';

        \file_put_contents($file, "<?php\nnamespace {$namespace};\n{$uses}\n{$modifier}class Widget extends {$extends}\n{\n{$classBody}\n}\n");
        $this->files[] = $file;

        if ($declare) {
            require $file;
        }

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
        $this->assertStringStartsWith("['title' => \$this->title()", $view['data']);
    }

    /** Exact-case superset: every public member name collides, even one `data()` itself ignores. */
    #[Test]
    public function every_public_member_name_collides(): void
    {
        $file = $this->componentFile("public function render() { return view('v', ['data' => 1, 'render' => 2, 'a' => 3]); }");

        $this->assertSame(['a'], ComponentViewMap::build([$file])->get('v')['keys'] ?? null);
    }

    #[Test]
    public function whitespace_around_the_factory_call_still_reaches_the_parser(): void
    {
        $spaced = $this->componentFile("public function render() { return view ('spaced', ['a' => 1]); }");
        $facade = $this->componentFile("public function render() { return View :: make ('facade', ['a' => 1]); }", uses: 'use Illuminate\Support\Facades\View;');

        $map = ComponentViewMap::build([$spaced, $facade]);

        $this->assertNotNull($map->get('spaced'));
        $this->assertNotNull($map->get('facade'));
    }

    /**
     * A render() PHP itself rejects (private, static, on an abstract class) fatals on autoload, which
     * no catch survives: it must decline from the AST, before anything asks for the class.
     */
    #[Test]
    public function an_unloadable_render_declines_without_autoloading(): void
    {
        $requested = [];
        $recorder = static function (string $class) use (&$requested): void {
            $requested[] = $class;
        };
        \spl_autoload_register($recorder);

        try {
            foreach ([
                "private function render() { return view('v', ['a' => 1]); }",
                "public static function render() { return view('v', ['a' => 1]); }",
            ] as $body) {
                $this->assertNull(ComponentViewMap::build([$this->componentFile($body, declare: false)])->get('v'));
            }

            $abstract = \str_replace('class Widget', 'abstract class Widget', (string) \file_get_contents($this->componentFile(
                "public function render() { return view('v', ['a' => 1]); }",
                final: false,
                declare: false,
            )));
            \file_put_contents($this->files[\count($this->files) - 1], $abstract);
            $this->assertNull(ComponentViewMap::build([$this->files[\count($this->files) - 1]])->get('v'));
        } finally {
            \spl_autoload_unregister($recorder);
        }

        $this->assertSame([], \array_values(\array_filter($requested, static fn(string $class): bool => \str_starts_with($class, 'ComponentViewMapTest'))));
    }

    /** A project subclass inherits render() and can add public members that collide with its keys. */
    #[Test]
    public function a_non_final_component_a_project_class_extends_declines(): void
    {
        $parent = $this->componentFile("public function render() { return view('v', ['a' => 1]); }", final: false);
        $child = \sys_get_temp_dir() . '/ComponentViewMapTestChild' . \getmypid() . '.php';
        \file_put_contents($child, "<?php\nnamespace Elsewhere;\nuse Some\\Widget as Base;\nfinal class Special extends \\Some\\Widget {}\n");
        $this->files[] = $child;

        $this->assertNotNull(ComponentViewMap::build([$parent])->get('v'));
        $this->assertNull(ComponentViewMap::build([$parent, $child])->get('v'));
    }

    /** `vendor.pkg.card` and `pkg::card` can name one file; two components for it decline it. */
    #[Test]
    public function a_template_two_mapped_names_resolve_to_declines(): void
    {
        $map = ComponentViewMap::build([
            $this->componentFile("public function render() { return view('vendor.pkg.card', ['a' => 1]); }"),
            $this->componentFile("public function render() { return view('pkg::card', ['a' => 2]); }"),
            $this->componentFile("public function render() { return view('solo', ['a' => 3]); }"),
        ]);

        $resolved = $map->forTemplates([['vendor.pkg.card', '/t/card.blade.php'], ['pkg::card', '/t/card.blade.php'], ['solo', '/t/solo.blade.php']]);

        $this->assertSame(['/t/solo.blade.php'], \array_keys($resolved));
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

        foreach (['extractPublicProperties', 'extractPublicMethods', 'shouldIgnore', 'ignoredMethods'] as $method) {
            yield "a {$method}() override" => ["public function render() { return view('v', ['a' => 1]); }\nprotected function {$method}(\$name = null) { return parent::{$method}(...\\func_get_args()); }"];
        }

        yield 'a resolveView() override' => ["public function render() { return view('v', ['a' => 1]); }\npublic function resolveView() { return parent::resolveView(); }"];
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
