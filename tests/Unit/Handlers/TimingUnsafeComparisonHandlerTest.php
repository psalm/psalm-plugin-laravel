<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PhpParser\Node\Arg;
use PhpParser\Node\ArgPlaceholder;
use PhpParser\Node\Expr\Variable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Rules\TimingUnsafeComparisonHandler;

/**
 * Regression coverage for the ArgPlaceholder finding: PhpParser's `ArgPlaceholder` node is the
 * `?` of PHP 8.4 partial function application (e.g. `strcmp(?, $secret)`), which — unlike the
 * all-or-nothing `...` of first-class-callable syntax (`VariadicPlaceholder`) — CAN mix with
 * concrete arguments in one call. It still occupies its positional slot, so a later concrete
 * argument must never be misidentified as sitting at the placeholder's watched position.
 *
 * Why unit-level rather than a phpt or a full {@see \Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent}
 * wire-up: per this finding's own constraint, the existing phpt harness cannot express reaching
 * {@see TimingUnsafeComparisonHandler::afterExpressionAnalysis()} with this AST shape live — Psalm
 * resolves a `?`-bearing call to a closure-creation expression rather than a plain analyzed call
 * site, so a phpt fixture would exercise a different code path (or none) regardless of the fix.
 * And unlike the sibling handlers under this directory that stub a `Codebase` or a
 * `StatementsSource`, `afterExpressionAnalysis()` requires a *concrete* `StatementsAnalyzer`
 * (`instanceof` gate) whose `getFilePath()`/`getFileName()` chain bottoms out in a private,
 * non-nullable `source` property with no public constructor path — there is no seam to fake it
 * without a real Psalm analysis run (what the plugin's own `--taint-analysis` phpt suite already
 * exercises for the happy path). The bug and the fix live entirely in the pure, side-effect-free
 * positional resolver below, so that is what this test drives directly with hand-built PhpParser
 * AST — the same "reflect into the private resolver" pattern the rest of this test suite uses for
 * handler internals that don't need a live analyzer (see e.g. RelationMethodParserTest,
 * ResponseFactoryTaintHandlerTest).
 *
 * Mechanism under test: {@see TimingUnsafeComparisonHandler::afterExpressionAnalysis()} only
 * calls `addSinksForOperands()` (which registers the taint sinks that make a timing-unsafe
 * comparison get reported) when BOTH resolved operands are `Expr` instances:
 *
 *     if ($leftExpr instanceof Expr && $rightExpr instanceof Expr) { addSinksForOperands(...); }
 *
 * `resolveArgument()` returning null for a watched position that holds a placeholder is what
 * makes that guard fail — no sink is ever created for it. The prior, buggy shape simply
 * `continue`d past an `ArgPlaceholder` without recording that it had consumed a position, so the
 * *next* concrete argument was counted as sitting at the placeholder's index and got resolved
 * (and sunk) in its place. Each test below fails under that prior shape: it would return the
 * later concrete arg's value instead of null.
 */
#[CoversClass(TimingUnsafeComparisonHandler::class)]
final class TimingUnsafeComparisonHandlerTest extends TestCase
{
    /**
     * All entries in the private TIMING_UNSAFE_FUNCTIONS map watch the same two canonical
     * positions (0 and 1) — this is a genuinely shared path, not an assumption. Locking that in
     * here justifies exercising it once, via strcmp()'s spec, rather than once per function name.
     */
    #[Test]
    public function every_timing_unsafe_function_watches_positions_zero_and_one(): void
    {
        $reflection = new \ReflectionClass(TimingUnsafeComparisonHandler::class);
        /** @var array<string, array{array{string, int}, array{string, int}}> */
        $functions = $reflection->getReflectionConstant('TIMING_UNSAFE_FUNCTIONS')?->getValue() ?? [];

        $this->assertNotSame([], $functions);

        foreach ($functions as $name => [$leftSpec, $rightSpec]) {
            $this->assertSame(0, $leftSpec[1], "{$name}: left operand must watch position 0.");
            $this->assertSame(1, $rightSpec[1], "{$name}: right operand must watch position 1.");
        }
    }

    /**
     * `strcmp(?, $secret)` is valid PHP 8.4 partial application syntax: a placeholder occupying
     * the watched left position (0) must decline rather than letting the concrete arg that
     * follows it slide into that slot.
     */
    #[Test]
    public function it_declines_the_left_operand_when_a_placeholder_occupies_position_zero(): void
    {
        [$leftSpec] = $this->timingUnsafeSpec('strcmp');

        $args = [
            new ArgPlaceholder(),
            new Arg(new Variable('secret')),
        ];

        $resolved = $this->resolveArgument($args, $leftSpec[0], $leftSpec[1]);

        $this->assertNull(
            $resolved,
            'A placeholder at the watched left position must decline, not resolve to the following concrete arg.',
        );
    }

    /**
     * Same regression, right operand (position 1): a concrete leading arg is present (so the
     * placeholder is genuinely at index 1, not 0), and a further concrete arg follows the
     * placeholder. The prior buggy resolver would count that trailing arg at index 1 — because it
     * never advanced the index past the placeholder — and wrongly resolve/sink it.
     */
    #[Test]
    public function it_declines_the_right_operand_when_a_placeholder_occupies_position_one(): void
    {
        [, $rightSpec] = $this->timingUnsafeSpec('strcmp');

        $args = [
            new Arg(new Variable('other')),
            new ArgPlaceholder(),
            new Arg(new Variable('secret')),
        ];

        $resolved = $this->resolveArgument($args, $rightSpec[0], $rightSpec[1]);

        $this->assertNull(
            $resolved,
            'A placeholder at the watched right position must decline, not resolve to the trailing concrete arg.',
        );
    }

    /**
     * Both watched operands resolving to `Expr` is exactly the condition
     * {@see TimingUnsafeComparisonHandler::afterExpressionAnalysis()} checks before registering
     * any taint sink (`$leftExpr instanceof Expr && $rightExpr instanceof Expr`). Uses the same
     * three-arg shape as the right-position test above: under the prior buggy resolver, the left
     * operand resolves correctly (`$other`, genuinely at position 0) AND the right operand
     * wrongly resolves to the trailing `$secret` — so the guard evaluated true and a sink got
     * registered for the misattributed expression. With the placeholder correctly declining the
     * watched right position, the pair can never satisfy the guard, so no timing-comparison sink
     * is ever added to the taint graph for this call — the observable "no report" outcome the
     * finding requires.
     */
    #[Test]
    public function a_placeholder_at_a_watched_position_prevents_the_operand_pair_from_ever_resolving(): void
    {
        [$leftSpec, $rightSpec] = $this->timingUnsafeSpec('strcmp');

        $args = [
            new Arg(new Variable('other')),
            new ArgPlaceholder(),
            new Arg(new Variable('secret')),
        ];

        $leftExpr = $this->resolveArgument($args, $leftSpec[0], $leftSpec[1]);
        $rightExpr = $this->resolveArgument($args, $rightSpec[0], $rightSpec[1]);

        $this->assertFalse(
            $leftExpr instanceof \PhpParser\Node\Expr && $rightExpr instanceof \PhpParser\Node\Expr,
            'A resolvable operand pair must never occur when the watched position holds a placeholder.',
        );
    }

    /**
     * Baseline control: without a placeholder, the same two-arg call resolves both operands
     * normally. This guards against the null-returning tests above passing vacuously (e.g. a
     * resolver that always returns null regardless of the placeholder).
     */
    #[Test]
    public function it_still_resolves_both_operands_when_no_placeholder_is_present(): void
    {
        [$leftSpec, $rightSpec] = $this->timingUnsafeSpec('strcmp');

        $left = new Variable('known');
        $right = new Variable('secret');
        $args = [new Arg($left), new Arg($right)];

        $this->assertSame($left, $this->resolveArgument($args, $leftSpec[0], $leftSpec[1]));
        $this->assertSame($right, $this->resolveArgument($args, $rightSpec[0], $rightSpec[1]));
    }

    /**
     * @return array{array{string, int}, array{string, int}}
     */
    private function timingUnsafeSpec(string $function): array
    {
        $reflection = new \ReflectionClass(TimingUnsafeComparisonHandler::class);
        /** @var array<string, array{array{string, int}, array{string, int}}> */
        $functions = $reflection->getReflectionConstant('TIMING_UNSAFE_FUNCTIONS')?->getValue() ?? [];

        $this->assertArrayHasKey($function, $functions);

        return $functions[$function];
    }

    /**
     * @param array<Arg|ArgPlaceholder> $args
     */
    private function resolveArgument(array $args, string $name, int $position): ?\PhpParser\Node\Expr
    {
        $method = new \ReflectionMethod(TimingUnsafeComparisonHandler::class, 'resolveArgument');

        /** @var ?\PhpParser\Node\Expr */
        return $method->invoke(null, $args, $name, $position);
    }
}
