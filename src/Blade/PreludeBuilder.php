<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * Builds the leading `<?php … ?>` block of standalone one-line docblocks
 * (`/** @var \FQCN $name *\/`) that seeds every shadow file. Psalm reads a
 * standalone `@var` docblock as declaring that variable's type for the rest
 * of the scope, which silences UndefinedGlobalVariable for names Blade
 * injects at render time but the compiled output never assigns.
 *
 * @internal
 */
final class PreludeBuilder
{
    /** @var array<string, string> variable name (without $) => FQCN, always present in a compiled view */
    public const AMBIENT_TYPES = [
        '__env' => '\Illuminate\View\Factory',
        'errors' => '\Illuminate\Support\ViewErrorBag',
        'attributes' => '\Illuminate\View\ComponentAttributeBag',
        'slot' => '\Illuminate\View\ComponentSlot',
        'component' => '\Illuminate\View\Component',
        // Blade's loop cursor is a plain stdClass built from an array (ManagesLoops::getLastLoop()).
        'loop' => 'object{index: int, iteration: int, remaining: int|null, count: int|null, first: bool, last: bool|null, odd: bool, even: bool, depth: int, parent: object|null}',
    ];

    /**
     * @param array<string, string> $contractVars variable name (without $) => FQCN
     */
    public function build(string $compiled, array $contractVars): string
    {
        $declared = self::AMBIENT_TYPES + $contractVars;

        $lines = [];

        foreach (self::AMBIENT_TYPES as $name => $type) {
            $lines[] = "/** @var {$type} \${$name} */";
        }

        foreach ($contractVars as $name => $type) {
            $lines[] = "/** @var {$type} \${$name} */";
        }

        foreach ($this->undeclaredVariables($compiled, $declared) as $name) {
            $lines[] = "/** @var mixed \${$name} */";
        }

        return "<?php\n" . \implode("\n", $lines) . "\n?>\n";
    }

    /**
     * The AMBIENT_TYPES entries that name a real class, for a caller that must queue them for
     * scanning (BladeBootstrapper) rather than read their docblock type. Excludes `loop`, whose
     * value is an inline object shape, not a class name.
     *
     * @return list<string>
     */
    public static function ambientClassNames(): array
    {
        return \array_values(\array_filter(
            self::AMBIENT_TYPES,
            static fn(string $type): bool => $type[0] === '\\',
        ));
    }

    /**
     * @param array<string, string> $declared
     * @return list<string> variable names (without $), sorted, deduplicated
     */
    private function undeclaredVariables(string $compiled, array $declared): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $ast = $parser->parse($compiled) ?? [];
        } catch (\Throwable) {
            return [];
        }

        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<string, true> */
            public array $found = [];

            #[\Override]
            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Expr\Variable && \is_string($node->name)) {
                    $this->found[$node->name] = true;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $names = [];

        foreach (\array_keys($visitor->found) as $name) {
            if (\str_starts_with($name, '__') || isset($declared[$name])) {
                continue;
            }

            $names[] = $name;
        }

        \sort($names);

        return $names;
    }
}
