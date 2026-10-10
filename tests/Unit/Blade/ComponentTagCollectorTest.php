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
        $php = "<?php \$__env->slot('footer', null, []); ?><?php \$__env->slot(\$name, null, []); ?><?php \$other->slot('x'); ?>";

        $this->assertSame(['footer'], ComponentTagCollector::collect(\token_get_all($php))[1]);
    }
}
