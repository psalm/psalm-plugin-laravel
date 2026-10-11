<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ComponentTagCollector;

#[CoversClass(ComponentTagCollector::class)]
final class ComponentTagCollectorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function renders(): iterable
    {
        // CompilesComponents::compileClassComponentOpening(), Laravel 13: a bare qualified name.
        yield 'x-tag' => ['<?php $component = App\View\Components\Alert::resolve([] + []); ?>', ['app\view\components\alert']];
        yield 'leading backslash' => ['<?php $component = \App\Alert::resolve([]); ?>', ['app\alert']];
        yield 'quoted literal' => ["<?php \$component = 'App\\\\Alert'::resolve([]); ?>", ['app\alert']];
        yield '@component(Foo::class)' => ['<?php $component = App\Alert::class::resolve([]); ?>', ['app\alert']];
        yield 'comments and spacing' => ["<?php \$component /* c */ =\n App\\Alert :: resolve ([]); ?>", ['app\alert']];
        yield 'another variable' => ['<?php $other = App\Alert::resolve([]); ?>', []];
        yield 'another static method' => ['<?php $component = App\Alert::make([]); ?>', []];
        yield 'dynamic class' => ['<?php $component = $class::resolve([]); ?>', []];
        yield 'inline html text' => ['$component = App\Alert::resolve([])', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('renders')]
    #[Test]
    public function collects_the_classes_a_compiled_tag_renders(string $php, array $expected): void
    {
        $this->assertSame($expected, ComponentTagCollector::collect(\token_get_all($php))[0]);
    }

    #[Test]
    public function collects_literal_named_slots_only(): void
    {
        $php = "<?php \$__env->slot('footer', null, []); ?><?php \$__env->slot(\"title\"); ?>"
            . "<?php \$__env->slot(\$name, null, []); ?><?php \$other->slot('x'); ?>";

        $this->assertSame(['footer', 'title'], ComponentTagCollector::collect(\token_get_all($php))[1]);
    }

    /**
     * The by-name render directives, as Laravel 13 compiles them: every literal in the call's
     * arguments counts, so an `@includeWhen` condition or `@includeFirst` list cannot hide a name.
     */
    #[Test]
    public function collects_views_rendered_by_name(): void
    {
        $php = "<?php echo \$__env->make(\"a.include\", ['x' => 1], array_diff_key(get_defined_vars(), ['__data' => 1]))->render(); ?>"
            . "<?php echo \$__env->renderWhen(\$f, 'a.when', []); ?>"
            . "<?php echo \$__env->first(['a.first', 'a.second'], [])->render(); ?>"
            . "<?php echo \$__env->renderEach('a.each', \$xs, 'x'); ?>"
            . "<?php \$__env->startComponent('a.component'); ?>"
            . "<?php \$__env->startComponent(\$component->resolveView(), \$component->data()); ?>"
            . "<?php echo \$other->make('not.a.view'); ?>";

        $this->assertSame(
            ['a.include', 'x', '__data', 'a.when', 'a.first', 'a.second', 'a.each', 'a.component'],
            ComponentTagCollector::collect(\token_get_all($php))[2],
        );
    }
}
