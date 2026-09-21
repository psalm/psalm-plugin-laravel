<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
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
    /** @var array<string, string> variable name (without $) => FQCN, present in EVERY compiled view */
    public const AMBIENT_TYPES = [
        '__env' => '\Illuminate\View\Factory',
        'errors' => '\Illuminate\Support\ViewErrorBag',
        // Blade's loop cursor is a plain stdClass built from an array (ManagesLoops::getLastLoop()).
        'loop' => 'object{index: int, iteration: int, remaining: int|null, count: int|null, first: bool, last: bool|null, odd: bool, even: bool, depth: int, parent: object|null}',
    ];

    /**
     * Every name Blade injects into a compiled view itself, declared or not (`$component` is
     * NEVER declared, see {@see componentTypesFor()}). This is the "Blade owns this name"
     * question a read-set filter or UnusedViewData check asks, distinct from AMBIENT_TYPES,
     * which asks "does the prelude always declare a type for this name".
     */
    public const BLADE_OWNED_NAMES = [
        '__env' => true, 'errors' => true, 'loop' => true,
        'attributes' => true, 'slot' => true, 'component' => true,
    ];

    private const COMPONENT_ATTRIBUTES_TYPE = '\Illuminate\View\ComponentAttributeBag';

    private const COMPONENT_SLOT_TYPE = '\Illuminate\View\ComponentSlot';

    private ?Parser $parser = null;

    /**
     * @param array<string, string> $contractVars variable name (without $) => FQCN
     */
    public function build(string $compiled, array $contractVars, string $source): string
    {
        $componentTypes = \array_filter(
            self::componentTypesFor($source),
            static fn(?string $type): bool => $type !== null,
        );

        $declared = self::AMBIENT_TYPES + $componentTypes + $contractVars;

        $lines = [];

        foreach (self::AMBIENT_TYPES as $name => $type) {
            $lines[] = "/** @var {$type} \${$name} */";
        }

        foreach ($componentTypes as $name => $type) {
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
     * `$attributes` and `$slot`, typed from the template's own SOURCE alone (never its compiled
     * output, never a cross-template pass): a caller of `<x-foo/>` never writes either name
     * itself, so any match means this template IS a component view (reachable as `<x-*>`,
     * `@component`, or both — {@see \Illuminate\View\Concerns\ManagesComponents::componentData()}
     * gives both render paths the same ComponentSlot for `$slot`; only `$attributes` is
     * render-path-sensitive, and that distinction cannot be told apart statically here).
     *
     * `@props([...])` runs `$attributes ??= new ComponentAttributeBag(...)`
     * (`Concerns/CompilesComponents.php::compileProps()`), so only that branch may see the
     * attributes bag as genuinely absent and gets the nullable type; `@aware([...])` emits no such
     * assignment, and a bare `$attributes` mention with neither directive means Laravel's own
     * `Component::data()` / `AnonymousComponent::data()` already guarantees the key, so both get
     * the non-null type.
     *
     * @return array{attributes: ?string, slot: ?string}
     */
    public static function componentTypesFor(string $source): array
    {
        $attributes = null;

        if (\preg_match('/@props\s*\(/', $source) === 1) {
            $attributes = '?' . self::COMPONENT_ATTRIBUTES_TYPE;
        } elseif (\preg_match('/@aware\s*\(/', $source) === 1 || \str_contains($source, '$attributes')) {
            $attributes = self::COMPONENT_ATTRIBUTES_TYPE;
        }

        $slot = \str_contains($source, '$slot') ? self::COMPONENT_SLOT_TYPE : null;

        return ['attributes' => $attributes, 'slot' => $slot];
    }

    /** Whether {@see componentTypesFor()} declares anything at all for this template's source. */
    public static function isComponentView(string $source): bool
    {
        return self::componentTypesFor($source) !== ['attributes' => null, 'slot' => null];
    }

    /**
     * Every class name a shadow's prelude can ever emit a docblock for, for a caller that must
     * queue them all for scanning (BladeBootstrapper) rather than read their docblock type. Both
     * AMBIENT_TYPES (always declared) and the component-only names (declared in SOME shadows only)
     * belong here: Psalm's scanner never reads a class name out of a stacked prelude docblock, so
     * a name absent from this list reports UndefinedDocblockClass the moment any shadow declares
     * it. Excludes `loop`, whose value is an inline object shape, not a class name.
     *
     * @return list<string>
     */
    public static function ambientClassNames(): array
    {
        $types = self::AMBIENT_TYPES + [
            'attributes' => self::COMPONENT_ATTRIBUTES_TYPE,
            'slot' => self::COMPONENT_SLOT_TYPE,
        ];

        return \array_values(\array_filter(
            $types,
            static fn(string $type): bool => $type[0] === '\\',
        ));
    }

    /** One parser for every template in the pass: constructing one re-reads PHP's own token tables. */
    private function parser(): Parser
    {
        return $this->parser ??= (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @param array<string, string> $declared
     * @return list<string> variable names (without $), sorted, deduplicated
     */
    private function undeclaredVariables(string $compiled, array $declared): array
    {
        $parser = $this->parser();

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
