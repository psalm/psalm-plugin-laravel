<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Stubs;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Psalm does not propagate taint sinks between an interface and its implementation, so a
 * contract stub has to restate the sinks its concrete counterpart declares. Those pairs are
 * kept in sync by a comment today; this turns that into a loud failure.
 *
 * Only method names present in BOTH files are compared: a contract stub deliberately
 * restates just the sink-carrying subset, and the concrete stub carries methods the
 * interface does not declare.
 */
#[CoversNothing]
final class ContractStubSinkParityTest extends TestCase
{
    /** @return iterable<string, array{string, string, class-string}> */
    public static function contractConcretePairs(): iterable
    {
        yield 'ResponseFactory' => [
            'Contracts/Routing/ResponseFactory.phpstub',
            'Routing/ResponseFactory.phpstub',
            \Illuminate\Contracts\Routing\ResponseFactory::class,
        ];

        yield 'View factory' => [
            'Contracts/View/Factory.phpstub',
            'View/Factory.phpstub',
            \Illuminate\Contracts\View\Factory::class,
        ];
    }

    /** @param class-string $interface */
    #[Test]
    #[DataProvider('contractConcretePairs')]
    public function contract_stub_mirrors_the_concrete_sinks(string $contractPath, string $concretePath, string $interface): void
    {
        $contractSinks = $this->parseSinksByMethod($contractPath);
        $concreteSinks = $this->parseSinksByMethod($concretePath);

        $shared = \array_intersect_key($contractSinks, $concreteSinks);

        // Without this the test would pass vacuously if the parser silently matched nothing.
        $this->assertNotSame([], $shared, "No method names overlap between {$contractPath} and {$concretePath}; the parser or the paths are wrong.");

        foreach ($shared as $method => $expected) {
            $this->assertSame($concreteSinks[$method], $expected, \sprintf(
                "Taint sinks drifted on %s(). Keep the contract stub in sync with the concrete one.\n  %s: %s\n  %s: %s",
                $method,
                $concretePath,
                $concreteSinks[$method] === [] ? '(none)' : \implode(', ', $concreteSinks[$method]),
                $contractPath,
                $expected === [] ? '(none)' : \implode(', ', $expected),
            ));
        }
    }

    /**
     * Parity above only compares methods both stubs already declare, so a sink the contract
     * stub simply never restated is invisible to it. Close that: every sink-bearing concrete
     * method the real interface also declares must appear in the contract stub, because a
     * contract-typed receiver reaches nothing else.
     *
     * @param class-string $interface
     */
    #[Test]
    #[DataProvider('contractConcretePairs')]
    public function contract_stub_restates_every_sink_the_interface_exposes(string $contractPath, string $concretePath, string $interface): void
    {
        $this->assertTrue(\interface_exists($interface), "{$interface} is not autoloadable; the pair is misconfigured.");

        $contractSinks = $this->parseSinksByMethod($contractPath);
        $covered = 0;

        foreach ($this->parseSinksByMethod($concretePath) as $method => $sinks) {
            // Methods the interface does not declare (View's first/renderWhen/...) can only
            // ever be called on the concrete class, so the contract stub must not grow them.
            if ($sinks === [] || !\method_exists($interface, $method)) {
                continue;
            }

            ++$covered;

            $this->assertArrayHasKey($method, $contractSinks, \sprintf(
                "%s::%s() carries sinks (%s) and is declared on %s, but %s does not restate it. "
                . 'A contract-typed receiver would reach no sink at all.',
                $concretePath,
                $method,
                \implode(', ', $sinks),
                $interface,
                $contractPath,
            ));
        }

        $this->assertGreaterThan(0, $covered, "No sink-bearing interface method found for {$interface}; the parser or the pair is wrong.");
    }

    #[Test]
    public function parser_finds_the_sinks_it_is_asked_to_compare(): void
    {
        $sinks = $this->parseSinksByMethod('Routing/ResponseFactory.phpstub');

        // Guards the regex itself: a parser that returned empty sets everywhere would make
        // every parity assertion above trivially true.
        $this->assertSame(['@psalm-taint-sink html $content'], $sinks['make'] ?? []);

        // The view NAME is sunk (it selects which blade template executes); the view DATA
        // is not.
        $this->assertSame(
            ['@psalm-taint-sink include $view'],
            $sinks['view'] ?? [],
        );

        $this->assertSame(
            [],
            $sinks['json'] ?? ['unparsed'],
            'json() must stay un-sunk: it is served as application/json, not rendered as HTML.',
        );
    }

    /**
     * Maps every method declared in a stub to its sorted list of taint-sink annotations.
     * Methods without a docblock, or with one carrying no sink, map to an empty list.
     *
     * Deliberately a plain text parse (no Psalm APIs): the point is to diff what the two
     * files literally say, before any stub merging or inheritance can paper over a gap.
     *
     * @return array<string, list<string>>
     */
    private function parseSinksByMethod(string $relativePath): array
    {
        $path = \dirname(__DIR__, 3) . '/stubs/common/' . $relativePath;

        $this->assertFileExists($path);

        $source = \file_get_contents($path);
        $this->assertIsString($source);

        // Modifiers are part of the match so the offset lands before them: otherwise the
        // `public ` between a docblock and `function` hides the docblock from extractSinks().
        $matched = \preg_match_all(
            '/(?:(?:public|protected|private|static|abstract|final)\s+)*function\s+(\w+)\s*\(/',
            $source,
            $matches,
            \PREG_OFFSET_CAPTURE,
        );
        $this->assertNotFalse($matched);

        $sinksByMethod = [];
        $previousEnd = 0;

        foreach ($matches[1] as $index => $nameMatch) {
            $declarationStart = $matches[0][$index][1];
            $preceding = \substr($source, $previousEnd, $declarationStart - $previousEnd);
            $previousEnd = $declarationStart;

            $sinksByMethod[$nameMatch[0]] = $this->extractSinks($preceding);
        }

        return $sinksByMethod;
    }

    /**
     * Pulls the sink annotations out of the docblock attached to a declaration. The docblock
     * counts as attached only when it is the last thing before the declaration, so a class
     * docblock is never misread as belonging to the first method.
     *
     * @return list<string>
     */
    private function extractSinks(string $preceding): array
    {
        $trimmed = \rtrim($preceding);

        if (!\str_ends_with($trimmed, '*/')) {
            return [];
        }

        $docblockStart = \strrpos($trimmed, '/**');

        if ($docblockStart === false) {
            return [];
        }

        \preg_match_all(
            '/@psalm-taint-sink\s+(\S+)\s+(\$\w+)/',
            \substr($trimmed, $docblockStart),
            $matches,
            \PREG_SET_ORDER,
        );

        $sinks = \array_map(
            static fn(array $match): string => \sprintf('@psalm-taint-sink %s %s', $match[1], $match[2]),
            $matches,
        );

        \sort($sinks);

        return $sinks;
    }
}
