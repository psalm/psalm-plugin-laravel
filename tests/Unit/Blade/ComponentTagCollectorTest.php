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
        yield 'quoted literal' => ["<?php \$component = 'App\\\\Alert'::resolve([]); ?>", ['app\alert']];
        yield '@component(Foo::class)' => ['<?php $component = App\Alert::class::resolve([]); ?>', ['app\alert']];
        yield 'another variable' => ['<?php $other = App\Alert::resolve([]); ?>', []];
        yield 'dynamic class' => ['<?php $component = $class::resolve([]); ?>', []];
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

    /**
     * #2216: an app's own `@include` (BookStack's theme override) hands the scope to a renderer that is
     * not `$__env`. It may render the view with none of `Component::data()`'s keys, so the name counts.
     */
    #[Test]
    public function collects_views_a_custom_directive_renders_with_the_whole_scope(): void
    {
        $scope = "array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1])";
        $php = "<?php echo \$__themeViews->handleViewInclude('a.themed', {$scope}); ?>"
            . "<?php echo \$__themeViews->handleViewInclude(\"a.data\", ['k' => 1], {$scope}); ?>"
            . "<?php echo Theme::render(['a.first', 'a.second'], {$scope}); ?>"
            . "<?php echo \$other->handleViewInclude('c.nested', foo({$scope})); ?>"
            . "<?php echo \$other->handleViewInclude('c.qualified', \\array_diff_key(\\get_defined_vars(), ['__data' => 1])); ?>";

        $this->assertSame(
            ['a.themed', '__data', '__path', 'a.data', 'k', 'a.first', 'a.second', 'c.nested', 'c.qualified'],
            ComponentTagCollector::collect(\token_get_all($php))[2],
        );
    }

    /**
     * Only the exact `array_diff_key(get_defined_vars(), …)` argument marks a render: a bare
     * `get_defined_vars()` (Filament's `@capture`) or an unrelated `array_diff_key()` is no include.
     */
    #[Test]
    public function ignores_calls_that_do_not_hand_over_the_compiled_scope(): void
    {
        $php = "<?php echo \$__themeViews->handleViewInclude('not.scope', ['k' => 1]); ?>"
            . "<?php \$capture->run('not.capture', get_defined_vars()); ?>"
            . "<?php \$x = array_diff_key(\$a, ['other' => 1]); \$y = \$o->with('not.diff', \$x); ?>"
            . "<?php echo array_diff_key(get_defined_vars(), ['__data' => 1]); ?>";

        $this->assertSame([], ComponentTagCollector::collect(\token_get_all($php))[2]);
    }
}
