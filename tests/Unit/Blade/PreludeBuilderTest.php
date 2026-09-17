<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\PreludeBuilder;

#[CoversClass(PreludeBuilder::class)]
final class PreludeBuilderTest extends TestCase
{
    #[Test]
    public function includes_ambient_vars(): void
    {
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', []);

        foreach (['__env', 'errors', 'attributes', 'slot', 'component', 'loop'] as $name) {
            $this->assertStringContainsString("\${$name} */", $prelude);
        }
    }

    #[Test]
    public function includes_contract_vars_with_given_type(): void
    {
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', ['user' => '\App\Models\User']);

        $this->assertStringContainsString('@var \App\Models\User $user */', $prelude);
    }

    #[Test]
    public function undeclared_variable_gets_var_mixed(): void
    {
        $prelude = (new PreludeBuilder())->build('<?php echo $foo; ?>', []);

        $this->assertStringContainsString('@var mixed $foo */', $prelude);
    }

    #[Test]
    public function underscore_prefixed_variables_are_excluded(): void
    {
        $prelude = (new PreludeBuilder())->build('<?php echo $__key; ?>', []);

        $this->assertStringNotContainsString('$__key', $prelude);
    }

    #[Test]
    public function declared_variables_are_not_duplicated_as_mixed(): void
    {
        $prelude = (new PreludeBuilder())->build('<?php echo $errors; ?>', []);

        $this->assertSame(1, \substr_count($prelude, '$errors'));
    }

    #[Test]
    public function wraps_content_in_a_single_php_block(): void
    {
        $prelude = (new PreludeBuilder())->build('<?php echo 1; ?>', []);

        $this->assertSame(1, \substr_count($prelude, '<?php'));
        $this->assertSame(1, \substr_count($prelude, '?>'));
    }
}
