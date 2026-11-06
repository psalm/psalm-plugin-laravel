<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Views\ViewContractHandler;

/**
 * How `ViewContractHandler` classifies a passed value that does not satisfy the declared type, the
 * way Psalm's argument analysis does: a hard mismatch is `InvalidViewData`, a value that only partly
 * fits (possibly null, a parent type, a union with a member that does not fit) is the lower-severity
 * `PossiblyInvalidViewData`, and a value of unknown type is not judged at all.
 *
 * One real run over `Fixtures/ViewDataClassification`, a file per behavior. Kept apart from
 * `ViewContractValidationTest` so the fixtures of the two cannot collide.
 */
#[CoversClass(ViewContractHandler::class)]
#[Group('subprocess')]
final class ViewDataClassificationTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/ViewDataClassification';

    private const HARD = 'InvalidViewData';

    private const PARTIAL = 'PossiblyInvalidViewData';

    /** Severity under `<experimental />` versus a bare `<blade />` is pinned by `ExperimentalIssuePolicyTest`. */
    private const ARGUMENTS = ['-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json', '--show-info=true'];

    /**
     * Issues of the given types a fixture file caused.
     *
     * @return list<array{type: string, message: string}>
     */
    private function issuesIn(string $file, string ...$types): array
    {
        $issues = [];

        foreach ($this->fixtureIssues(self::FIXTURE, self::ARGUMENTS) as $issue) {
            if (\basename((string) $issue['file_name']) === $file && \in_array($issue['type'], $types, true)) {
                $issues[] = [
                    'type' => (string) $issue['type'],
                    'message' => (string) $issue['message'],
                ];
            }
        }

        return $issues;
    }

    /** @return list<array{type: string, message: string}> */
    private function viewIssuesIn(string $file): array
    {
        return $this->issuesIn($file, self::HARD, self::PARTIAL);
    }

    #[Test]
    public function an_incompatible_value_is_a_hard_mismatch(): void
    {
        $issues = $this->viewIssuesIn('Incompatible.php');

        // A scalar, an unrelated class, a bare null and a union none of whose members fits.
        $this->assertSame([self::HARD, self::HARD, self::HARD, self::HARD], \array_column($issues, 'type'), \var_export($issues, true));
    }

    #[Test]
    public function a_possibly_null_value_is_a_partial_mismatch(): void
    {
        $issues = $this->viewIssuesIn('PossiblyNull.php');

        $this->assertSame([self::PARTIAL, self::PARTIAL], \array_column($issues, 'type'), \var_export($issues, true));
        $this->assertStringContainsString('ViewClassifyFixture\Member|null, which may be null.', $issues[0]['message']);
        // The Renderable form renders to the declared string: only the null is left to report.
        $this->assertStringContainsString('which may be null.', $issues[1]['message']);
    }

    #[Test]
    public function a_possibly_false_value_is_a_partial_mismatch_and_a_bare_false_a_hard_one(): void
    {
        $issues = $this->viewIssuesIn('PossiblyFalse.php');

        $this->assertSame([self::PARTIAL, self::HARD], \array_column($issues, 'type'), \var_export($issues, true));
        $this->assertStringContainsString('which may be false.', $issues[0]['message']);
    }

    #[Test]
    public function a_union_with_a_member_that_does_not_fit_is_a_partial_mismatch(): void
    {
        $issues = $this->viewIssuesIn('PartialUnion.php');

        $this->assertSame([self::PARTIAL], \array_column($issues, 'type'), \var_export($issues, true));
        $this->assertStringContainsString('which may be a different type.', $issues[0]['message']);
    }

    #[Test]
    public function an_interface_passed_for_its_implementation_is_a_partial_mismatch(): void
    {
        $issues = $this->viewIssuesIn('InterfaceToConcrete.php');

        $this->assertSame([self::PARTIAL], \array_column($issues, 'type'), \var_export($issues, true));
        $this->assertStringContainsString('which is wider than the declared type.', $issues[0]['message']);
    }

    #[Test]
    public function an_unparameterized_generic_is_a_partial_mismatch(): void
    {
        $issues = $this->viewIssuesIn('RawGeneric.php');

        $this->assertCount(2, $issues, \var_export($issues, true));
        $this->assertSame(self::PARTIAL, $issues[0]['type']);
        $this->assertStringContainsString('which is wider than the declared type.', $issues[0]['message']);
        // Psalm's own argument check calls this one InvalidArgument too: the bounds the raw class
        // reads as (`array-key`, `Model`) are not what the declaration asks for.
        $this->assertSame(self::HARD, $issues[1]['type']);
    }

    /**
     * Psalm's own argument check rejects `collect([...list...])` for `Collection<int, array<string,
     * mixed>>`: the stub infers the key as `int<0, N>` and the key template is invariant. The view
     * comparison therefore agrees with core instead of being stricter, and stays a hard mismatch.
     * If core ever accepts it, this case goes silent and the view comparison has to follow.
     */
    #[Test]
    public function a_collect_of_a_literal_list_is_judged_like_psalms_own_argument_check(): void
    {
        $core = $this->issuesIn('LiteralList.php', 'InvalidArgument');
        $this->assertCount(1, $core, \var_export($core, true));

        $view = $this->viewIssuesIn('LiteralList.php');
        $this->assertSame([self::HARD], \array_column($view, 'type'), \var_export($view, true));
    }

    #[Test]
    public function a_value_of_unknown_type_is_not_judged(): void
    {
        $issues = $this->viewIssuesIn('MixedSupplied.php');

        $this->assertSame([], $issues, \var_export($issues, true));
    }
}
