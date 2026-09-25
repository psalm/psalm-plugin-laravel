<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins #1545, asymmetrically. A magic property fetch on a receiver sealed by
 * `sealAllProperties="true"` reaches Psalm through two emission sites for the SAME access.
 * `AtomicPropertyFetchAnalyzer`'s direct handling reports `UndefinedMagicPropertyFetch` at the real
 * node, which maps to the correct template line, and fires UNCONDITIONALLY once the receiver has a
 * magic getter — proven here across a plain variable, a static-call result, and an array offset.
 * `ExistingAtomicMethodCallAnalyzer`'s `__get` handling re-checks the same seal on a
 * `VirtualMethodCall` Psalm synthesizes internally, and reports `UndefinedThisPropertyFetch` again;
 * that synthesized node carries NO location attributes, so its issue is always unmapped, and it is
 * always the fetch duplicate — `ShadowIssueRelocator` drops it.
 *
 * The `__set` sibling looks identical but is NOT sound the same way (#1545 review):
 * `InstancePropertyAssignmentAnalyzer`'s `UndefinedMagicPropertyAssignment` twin requires a
 * resolved `$var_id` and is never emitted for a non-variable receiver (`Magic::make()->nope = 1`),
 * while the synthesized `UndefinedThisPropertyAssignment` fires regardless — so for that receiver
 * shape the synthesized issue is the ONLY diagnostic, and dropping it unconditionally would lose it
 * silently. The relocator therefore does NOT drop `UndefinedThisPropertyAssignment`: the pre-#1545
 * duplicate stays, on purpose, for every assignment shape including the plain-variable one.
 *
 * A real `vendor/bin/psalm` run is the only way to pin it: both emission sites only fire once the
 * fixture's `sealAllProperties="true"` config and a real magic-property access are analyzed together.
 */
#[CoversNothing]
final class UndefinedThisPropertyDuplicateTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/UndefinedThisProperty';

    /** @var list<string> */
    private const ARGUMENTS = ['-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'];

    /**
     * @return list<array{type: string, file: string, line: int, message: string}>
     */
    private function analyze(): array
    {
        $issues = [];

        foreach ($this->fixtureIssues(self::FIXTURE, self::ARGUMENTS) as $issue) {
            $issues[] = [
                'type' => (string) $issue['type'],
                'file' => \basename((string) $issue['file_name']),
                'line' => (int) $issue['line_from'],
                'message' => (string) $issue['message'],
            ];
        }

        return $issues;
    }

    /**
     * @param list<array{type: string, file: string, line: int, message: string}> $issues
     *
     * @return list<array{type: string, file: string, line: int, message: string}>
     */
    private function forTemplate(array $issues): array
    {
        return \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => $issue['file'] === 'missing-prop.blade.php',
        ));
    }

    /**
     * A silent assertion proves nothing if the template never compiled. The manifest records every
     * template that made it through `compileAll()` this run, keyed by its real path.
     *
     * @param list<array{type: string, file: string, line: int, message: string}> $issues
     */
    private function assertBladeAnalyzed(array $issues): void
    {
        $manifest = (string) \file_get_contents($this->fixtureShadows(self::FIXTURE, self::ARGUMENTS) . '/manifest.php');
        $templatePath = \realpath(self::FIXTURE . '/resources/views/missing-prop.blade.php');
        $this->assertIsString($templatePath, 'missing-prop.blade.php does not exist on disk.');
        $this->assertStringContainsString(
            $templatePath,
            $manifest,
            'missing-prop.blade.php was never compiled into a shadow, so the assertions below prove nothing.',
        );
        $this->assertNotSame([], $this->forTemplate($issues), $manifest);
    }

    #[Test]
    public function the_fetch_duplicate_is_dropped_across_every_receiver_shape(): void
    {
        $issues = $this->analyze();
        $this->assertBladeAnalyzed($issues);

        $reported = $this->forTemplate($issues);
        $types = \array_column($reported, 'type');

        // Never present, regardless of receiver shape (plain variable, static-call, array offset).
        $this->assertNotContains('UndefinedThisPropertyFetch', $types, \var_export($issues, true));

        // Three receiver shapes, three mapped twins, each on the real fetch's own template line:
        // line 6 `{{ $magic->missing }}`, line 10 `{{ \Fx\Magic::make()->missing }}`, line 11
        // `{{ $objects[0]->missing }}`.
        $fetchLines = \array_column(
            \array_filter($reported, static fn(array $issue): bool => $issue['type'] === 'UndefinedMagicPropertyFetch'),
            'line',
        );
        \sort($fetchLines);
        $this->assertSame([6, 10, 11], $fetchLines, \var_export($issues, true));
    }

    /**
     * #1545 review: the `__set` counterpart is NOT dropped, because its twin does not fire
     * unconditionally the way the fetch twin does. A non-variable receiver
     * (`\Fx\Magic::make()->nope = 1` at line 13) never gets `UndefinedMagicPropertyAssignment`, so
     * the unmapped `UndefinedThisPropertyAssignment` on line 1 is the ONLY diagnostic for it —
     * kept, on purpose, at the cost of also keeping it (as a real duplicate) for the
     * plain-variable assignment at line 8, which DOES also get its mapped twin.
     */
    #[Test]
    public function the_assignment_duplicate_is_kept_because_its_twin_is_not_unconditional(): void
    {
        $issues = $this->analyze();
        $this->assertBladeAnalyzed($issues);

        $reported = $this->forTemplate($issues);
        $types = \array_column($reported, 'type');

        $this->assertContains('UndefinedThisPropertyAssignment', $types, \var_export($issues, true));

        $unmapped = \array_values(\array_filter(
            $reported,
            static fn(array $issue): bool => $issue['type'] === 'UndefinedThisPropertyAssignment',
        ));
        $this->assertSame(1, $unmapped[0]['line'], \var_export($issues, true));
        $this->assertStringContainsString('(unmapped)', $unmapped[0]['message'], \var_export($issues, true));

        // The plain-variable receiver (line 8, `$magic->nope = 1;`) still gets its mapped twin.
        $magicAssignments = \array_values(\array_filter(
            $reported,
            static fn(array $issue): bool => $issue['type'] === 'UndefinedMagicPropertyAssignment',
        ));
        $this->assertCount(1, $magicAssignments, \var_export($issues, true));
        $this->assertSame(8, $magicAssignments[0]['line'], \var_export($issues, true));

        // The non-variable receiver (line 13, `\Fx\Magic::make()->nope = 1;`) never gets a mapped
        // twin — the kept unmapped duplicate above is its ONLY diagnostic.
        $this->assertNotContains(13, \array_column($magicAssignments, 'line'), \var_export($issues, true));
    }
}
