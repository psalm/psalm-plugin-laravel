<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\CompilerEnvironment;
use Tests\Psalm\LaravelPlugin\Unit\Blade\Fixtures\BoundClosures\BoundClosureProvider;
use Tests\Psalm\LaravelPlugin\Unit\Blade\Fixtures\BoundClosures\DollarBraceProvider;

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
            // Reads its bound `$this` (the compiler), so only the compiler-identity check trusts it.
            return '<?php /* ' . $this::class . ' */ ?>';
        });

        [$hash, $trusted] = CompilerEnvironment::describe($compiler);

        $this->assertTrue($trusted);
        $this->assertNotSame('', $hash);
    }

    /**
     * #1693: a plain closure registered in a service provider's `boot()` is bound to the provider.
     * A body that never reaches the bound object cannot compile differently because of it.
     */
    #[Test]
    public function a_provider_bound_closure_that_never_reaches_this_stays_trustworthy(): void
    {
        $compiler = $this->compiler();
        $compiler->directive('mine', (new BoundClosureProvider())->ignoresThis());
        $compiler->if('feature', (new BoundClosureProvider())->ignoresThis());

        [$hash, $trusted] = CompilerEnvironment::describe($compiler);

        $this->assertTrue($trusted);
        $this->assertNotSame('', $hash);
    }

    /** @return iterable<string, array{\Closure}> */
    public static function boundClosuresReachingThis(): iterable
    {
        $provider = new BoundClosureProvider();

        yield '$this property read' => [$provider->readsThis()];
        yield '$this in double-quoted interpolation' => [$provider->interpolatesThis()];
        yield '$this in a nested arrow fn' => [$provider->nestedArrowFnReadsThis()];
        yield 'static::' => [$provider->usesStatic()];
        yield 'self::' => [$provider->usesSelf()];
        yield 'parent::' => [$provider->usesParent()];
        yield 'variable variable' => [$provider->usesVariableVariable()];
        yield '${} interpolation' => [(new DollarBraceProvider())->interpolatesDollarBrace()];
        yield 'eval' => [$provider->usesEval()];
        yield 'include' => [$provider->usesInclude()];
        yield 'compact' => [$provider->usesCompact()];
        yield 'debug_backtrace' => [$provider->usesDebugBacktrace()];
        // Not closure literals: the method body lives elsewhere, so it stays untrusted by design.
        yield 'invokable object' => [\Closure::fromCallable($provider)];
        yield 'array callable' => [\Closure::fromCallable([$provider, 'compileWithoutThis'])];
    }

    #[Test]
    #[DataProvider('boundClosuresReachingThis')]
    public function a_bound_callable_that_may_reach_this_flips_trustworthy_to_false(\Closure $directive): void
    {
        $compiler = $this->compiler();
        $compiler->directive('mine', $directive);

        [$hash, $trusted] = CompilerEnvironment::describe($compiler);

        $this->assertFalse($trusted);
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

    /**
     * #1517 F1 (review finding): the file hash and captured static
     * variables alone cannot tell two DIFFERENT callables declared in the SAME vendor file apart.
     * `Str::upper` and `Str::lower` share a file and capture nothing, so without the reflected
     * name and source line range they described identically even though `@shout('x')` would
     * compile to opposite runtime behaviour.
     */
    #[Test]
    public function two_static_method_callables_from_the_same_unchanged_file_describe_differently(): void
    {
        $withUpper = $this->compiler();
        $withUpper->directive('shout', [Str::class, 'upper']);

        $withLower = $this->compiler();
        $withLower->directive('shout', [Str::class, 'lower']);

        [$upperHash, $upperTrusted] = CompilerEnvironment::describe($withUpper);
        [$lowerHash, $lowerTrusted] = CompilerEnvironment::describe($withLower);

        $this->assertTrue($upperTrusted);
        $this->assertTrue($lowerTrusted);
        $this->assertNotSame($upperHash, $lowerHash);
    }

    /**
     * A single closure-producing factory: both calls create the closure from the EXACT SAME
     * source line, so file, name, start/end line, and scope are all identical between them (the
     * #1517 F1 discriminators). Only the captured `$captured` VALUE differs, isolating this test
     * to the value-encoding fix (F2) rather than accidentally passing because of the F1 fix. The
     * closure body genuinely reads `$captured` (a real directive would use its captured
     * configuration to decide what to compile to), rather than merely capturing and discarding
     * it, so an automated dead-code pass cannot mistake the capture for unused and strip it.
     */
    private function directiveCapturing(mixed $captured): \Closure
    {
        return static function (string $expression) use ($captured): string {
            return '<?php /* ' . \get_debug_type($captured) . ' */ ?>';
        };
    }

    /**
     * #1517 F2 (external review finding #2): a hand-joined descriptor string let a crafted key
     * collide with another entry's flattened text. These two captured-variable shapes previously
     * serialized IDENTICALLY under the old `'key=>' . describeValue()` string join:
     * `['x' => new \stdClass(), 'y' => 'A']` flattened to `[x=>unresolvable:stdClass,y=>string:A]`,
     * byte-for-byte the same text a single-key array whose key IS that flattened string also
     * produced. The typed, JSON-encoded descriptor must keep them apart.
     */
    #[Test]
    public function differently_structured_captured_arrays_never_describe_identically(): void
    {
        $withColliding = $this->compiler();
        $withColliding->directive('mine', $this->directiveCapturing(['x' => new \stdClass(), 'y' => 'A']));

        $withForged = $this->compiler();
        $withForged->directive('mine', $this->directiveCapturing(['x=>unresolvable:stdClass,y' => 'A']));

        [$collidingHash] = CompilerEnvironment::describe($withColliding);
        [$forgedHash] = CompilerEnvironment::describe($withForged);

        $this->assertNotSame($collidingHash, $forgedHash);
    }

    /**
     * #1517 F3 (review finding, subsumes a related subclass-state point from another review): a
     * compiler SUBCLASS can carry constructor-injected instance state
     * (`ThrowingBladeCompiler::$needle` changes what `compileString()` produces) that this class
     * has no way to enumerate off the class file alone, so any subclass instance is
     * unconditionally untrustworthy, not just when its file cannot be hashed.
     */
    #[Test]
    public function two_compiler_subclass_instances_differing_only_in_constructor_state_are_both_untrusted(): void
    {
        $withNeedleA = new ThrowingBladeCompiler(new Filesystem(), $this->root . '/compiled-a', '@needle-a');
        $withNeedleB = new ThrowingBladeCompiler(new Filesystem(), $this->root . '/compiled-b', '@needle-b');

        [$hashA, $trustedA] = CompilerEnvironment::describe($withNeedleA);
        [$hashB, $trustedB] = CompilerEnvironment::describe($withNeedleB);

        $this->assertFalse($trustedA);
        $this->assertFalse($trustedB);
        $this->assertNotSame('', $hashA);
        $this->assertNotSame('', $hashB);
    }

    /**
     * #1517 F4 (external review finding #6, non-blocking): an anonymous class's PHP-generated
     * name carries a per-process ordinal suffix (`class@anonymous.../file.php(N) : eval()'d
     * code$<hex>`) that shifts merely because an unrelated anonymous class loaded earlier in the
     * SAME process, nothing about the compiler subclass's own source changed. Two `eval()`'d
     * classes from the byte-identical source string, evaluated from the SAME call site, get
     * DIFFERENT raw names (proven directly below) purely from that ordinal; the descriptor must
     * not rotate because of it. (This is unconditionally untrustworthy anyway per F3, but the
     * shadow PATH must still be stable, or `prune()` never reclaims a superseded generation and
     * leaks one shadow per run forever, see `ShadowManifest::prune()`.)
     *
     * The ordinal is rendered in HEXADECIMAL, not decimal, and only a handful of anonymous classes
     * exist when this test runs alone, so it never naturally reaches a letter (`a`-`f`) unless run
     * inside a large suite that happens to have created enough of them first, which is exactly how
     * an earlier, decimal-only version of the fix (and this test) both passed in isolation and
     * failed under the full suite. The pair is rebuilt until the first one's ordinal carries a letter,
     * which forces that case deterministically instead of depending on how many other tests ran first.
     */
    #[Test]
    public function anonymous_compiler_subclasses_from_identical_source_describe_identically_despite_the_ordinal_suffix(): void
    {
        $anonymousCompilerSource = 'return new class(new \Illuminate\Filesystem\Filesystem(), $cachePath) '
            . 'extends \Illuminate\View\Compilers\BladeCompiler {};';

        // PHP's ordinal is one process-wide counter rendered in hex, so among 16 consecutive values
        // the last digit hits a letter at least once. Checking the class under test itself, not a
        // throwaway probe before it: a probe ending in `f` would hand the tested class `...0`.
        for ($attempt = 0; $attempt < 64; $attempt++) {
            $compilers = [];
            foreach (['/anon-a', '/anon-b'] as $suffix) {
                $cachePath = $this->root . $suffix;
                /** @var BladeCompiler $compiler */
                $compiler = eval($anonymousCompilerSource);
                $compilers[] = $compiler;
            }

            if (\preg_match('/\$[0-9a-fA-F]*[a-fA-F][0-9a-fA-F]*$/', $compilers[0]::class) === 1) {
                break;
            }
        }

        [$first, $second] = $compilers;

        // The raw PHP-generated names must actually differ (the ordinal truly shifted), or this
        // test would pass vacuously without ever exercising the normalization. At least one must
        // carry a hex LETTER, or it would not have caught the decimal-only regex this test exists
        // to pin.
        $this->assertNotSame($first::class, $second::class, 'the ordinal suffix must actually differ for this test to mean anything');
        $this->assertMatchesRegularExpression(
            '/\$[0-9a-fA-F]*[a-fA-F][0-9a-fA-F]*$/',
            $first::class,
            'the warmup must land on a hex-lettered ordinal, or this test never exercises the bug it pins',
        );

        [$firstHash, $firstTrusted] = CompilerEnvironment::describe($first);
        [$secondHash, $secondTrusted] = CompilerEnvironment::describe($second);

        $this->assertFalse($firstTrusted);
        $this->assertFalse($secondTrusted);
        $this->assertSame($firstHash, $secondHash, 'identical anonymous-subclass source must describe identically regardless of the process-local ordinal');
    }
}
