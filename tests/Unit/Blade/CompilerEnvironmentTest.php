<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\CompilerEnvironment;

#[CoversClass(CompilerEnvironment::class)]
final class CompilerEnvironmentTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $root = \tempnam(\sys_get_temp_dir(), 'compiler-env-');
        $this->assertIsString($root);
        \unlink($root);
        \mkdir($root, 0o777, true);
        $this->root = $root;
    }

    protected function tearDown(): void
    {
        $files = \glob($this->root . '/*');

        if ($files !== false) {
            foreach ($files as $file) {
                @\unlink($file);
            }
        }

        @\rmdir($this->root);
    }

    private function compiler(): BladeCompiler
    {
        return new BladeCompiler(new Filesystem(), $this->root . '/compiled');
    }

    #[Test]
    public function a_stock_compiler_is_trustworthy_and_stable_across_calls(): void
    {
        [$first, $firstTrusted] = CompilerEnvironment::describe($this->compiler());
        [$second, $secondTrusted] = CompilerEnvironment::describe($this->compiler());

        $this->assertTrue($firstTrusted);
        $this->assertTrue($secondTrusted);
        $this->assertNotSame('', $first);
        $this->assertSame($first, $second, 'two fresh, unconfigured compilers must describe identically');
    }

    #[Test]
    public function registering_a_custom_directive_changes_the_hash(): void
    {
        $plain = $this->compiler();
        [$plainHash] = CompilerEnvironment::describe($plain);

        $customized = $this->compiler();
        $customized->directive('mine', static fn(string $expression): string => '<?php ?>');
        [$customizedHash, $trusted] = CompilerEnvironment::describe($customized);

        $this->assertTrue($trusted);
        $this->assertNotSame($plain, $customizedHash);
        $this->assertNotSame($plainHash, $customizedHash);
    }

    /**
     * `getFileName()` returns `false` for a closure wrapping an internal function — there is no
     * source file to hash, so the input cannot be trusted rather than silently ignored.
     */
    #[Test]
    public function an_internal_function_directive_flips_trustworthy_to_false(): void
    {
        $compiler = $this->compiler();
        $compiler->directive('mine', 'strtoupper');

        [$hash, $trusted] = CompilerEnvironment::describe($compiler);

        $this->assertFalse($trusted);
        $this->assertNotSame('', $hash, 'the rest of the environment must still be described');
    }

    /**
     * `ReflectionFunction::getFileName()` on an `eval()`'d closure returns a string ending in
     * "eval()'d code", not a real path — nothing on disk to hash means the input is unresolvable.
     */
    #[Test]
    public function an_evald_directive_flips_trustworthy_to_false(): void
    {
        $compiler = $this->compiler();
        // Constructing a closure this way (rather than loading one from a real file that returns
        // it) is the only way to reliably produce `getFileName()` === ".../eval()'d code" in a
        // unit test; this is the ONLY eval() in this suite, and it exists to exercise exactly
        // that reflection edge case, not to execute untrusted input.
        $handler = eval("return static function (string \$expression): string { return '<?php ?>'; };"); // NOSONAR
        \assert(\is_callable($handler));
        $compiler->directive('mine', $handler);

        [$hash, $trusted] = CompilerEnvironment::describe($compiler);

        $this->assertFalse($trusted);
        $this->assertNotSame('', $hash);
    }

    /**
     * #1517 M1: `ReflectionFunction::getStaticVariables()` never sees a closure's bound `$this`,
     * so an invokable object's own constructor state is invisible to `describeCallable()` — two
     * instances with different state hash identically off their shared class file alone. Only
     * `trustworthy = false` is honest here; folding in the bound object's class would still miss
     * the actual state difference.
     */
    #[Test]
    public function an_invokable_object_directive_flips_trustworthy_to_false(): void
    {
        $compiler = $this->compiler();
        $compiler->directive('mine', new MarkerDirective('V1'));

        [$hash, $trusted] = CompilerEnvironment::describe($compiler);

        $this->assertFalse($trusted);
        $this->assertNotSame('', $hash);
    }

    /**
     * The mirror case: two invokable-object directives with different constructor state must not
     * describe identically, since a matching hash would let a stale shadow look fresh. Asserted
     * defensively even though `trustworthy = false` already forces a recompile on either side.
     */
    #[Test]
    public function two_invokable_object_directives_with_different_state_are_never_reported_trusted(): void
    {
        $withV1 = $this->compiler();
        $withV1->directive('mine', new MarkerDirective('V1'));

        $withV2 = $this->compiler();
        $withV2->directive('mine', new MarkerDirective('V2'));

        [, $v1Trusted] = CompilerEnvironment::describe($withV1);
        [, $v2Trusted] = CompilerEnvironment::describe($withV2);

        $this->assertFalse($v1Trusted);
        $this->assertFalse($v2Trusted);
    }

    /**
     * `bindDirective()` binds the handler to the `BladeCompiler` instance itself
     * (`BladeCompiler::directive($name, $handler, bind: true)`), not to arbitrary state — that
     * bound `$this` is the compiler being described, so it must stay trusted rather than joining
     * every other object-bound callable in `trustworthy = false`.
     */
    #[Test]
    public function bind_directive_stays_trustworthy_because_it_is_bound_to_the_compiler_itself(): void
    {
        $compiler = $this->compiler();
        $compiler->bindDirective('mine', function (string $expression): string {
            return '<?php ?>';
        });

        [$hash, $trusted] = CompilerEnvironment::describe($compiler);

        $this->assertTrue($trusted);
        $this->assertNotSame('', $hash);
    }

    #[Test]
    public function a_condition_registered_via_if_changes_the_hash_even_though_it_is_not_a_custom_directive(): void
    {
        $plain = $this->compiler();
        [$plainHash] = CompilerEnvironment::describe($plain);

        $withCondition = $this->compiler();
        $withCondition->if('disco', static fn(): bool => true);
        [$conditionHash, $trusted] = CompilerEnvironment::describe($withCondition);

        $this->assertTrue($trusted);
        $this->assertNotSame($plainHash, $conditionHash);
    }
}
