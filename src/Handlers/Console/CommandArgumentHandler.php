<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Console;

use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Internal\Arg;
use Psalm\LaravelPlugin\Issues\InvalidConsoleArgumentName;
use Psalm\LaravelPlugin\Issues\InvalidConsoleOptionName;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Narrows return types of Console Command methods based on the command's parsed signature:
 *
 * @see \Illuminate\Console\Concerns\InteractsWithIO::argument()
 * @see \Illuminate\Console\Concerns\InteractsWithIO::option()
 * @see \Illuminate\Console\Concerns\InteractsWithIO::hasArgument()
 * @see \Illuminate\Console\Concerns\InteractsWithIO::hasOption()
 *
 * argument()/option(): narrows the value type, and emits {@see InvalidConsoleArgumentName} /
 * {@see InvalidConsoleOptionName} when the requested name is not defined.
 *
 * hasArgument()/hasOption(): narrows to a literal true/false for a known signature and a literal
 * name. No undefined-name issue is emitted here — existence-testing is the method's purpose; a
 * definitely-absent name narrows to false, letting Psalm's own RedundantCondition surface the
 * resulting dead branch (parity with Larastan's HasArgument/HasOption extensions).
 */
final class CommandArgumentHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        // The argument()/option() methods are declared in the InteractsWithIO trait.
        // Psalm's declaring_method_ids resolves trait methods to the trait's FQCN,
        // so we must hook the trait, not the Command class itself.
        return [\Illuminate\Console\Concerns\InteractsWithIO::class];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        $methodName = $event->getMethodNameLowercase();

        // @todo Narrow arguments()/options() to a constant array shape with per-key types
        if (!\in_array($methodName, ['argument', 'option', 'hasargument', 'hasoption'], true)) {
            return null;
        }

        // Extract the literal string key from the first argument.
        // argument(null) / option(null) or no args fall through here too — typeAt
        // returns null for missing args, and a literal-null arg is not a string literal.
        $firstArgType = Arg::typeAt($event->getCallArgs(), $event->getSource(), 0);

        if (!$firstArgType instanceof \Psalm\Type\Union || !$firstArgType->isSingleStringLiteral()) {
            return null; // dynamic key, no arg, or null literal — cannot narrow
        }

        $key = $firstArgType->getSingleStringLiteral()->value;

        // Determine the concrete command class being analyzed.
        // getCalledFqClasslikeName() returns the concrete subclass (e.g., App\Console\Commands\Example),
        // getFqClasslikeName() returns the declaring class (Illuminate\Console\Concerns\InteractsWithIO).
        /** @var class-string|null $commandClass */
        $commandClass = $event->getCalledFqClasslikeName();

        if ($commandClass === null) {
            return null;
        }

        if ($methodName === 'argument') {
            return self::resolveArgumentType($commandClass, $key, $event);
        }

        if ($methodName === 'option') {
            return self::resolveOptionType($commandClass, $key, $event);
        }

        if ($methodName === 'hasargument') {
            return self::existenceToType(CommandDefinitionAnalyzer::hasArgument($commandClass, $key));
        }

        // hasoption — the only remaining name allowed by the guard above
        return self::existenceToType(CommandDefinitionAnalyzer::hasOption($commandClass, $key));
    }

    /**
     * Map the analyzer's existence result to a literal-bool return type for
     * hasArgument()/hasOption(). A null result (signature unavailable) leaves the
     * declared bool in place; otherwise narrows to literal true/false.
     *
     * @psalm-pure
     */
    private static function existenceToType(?bool $exists): ?Type\Union
    {
        if ($exists === null) {
            return null; // definition unavailable — cannot narrow
        }

        return $exists ? Type::getTrue() : Type::getFalse();
    }

    /**
     * @param class-string $commandClass
     */
    private static function resolveArgumentType(
        string $commandClass,
        string $name,
        MethodReturnTypeProviderEvent $event,
    ): ?Type\Union {
        $exists = CommandDefinitionAnalyzer::hasArgument($commandClass, $name);

        // Definition unavailable — cannot narrow
        if ($exists === null) {
            return null;
        }

        if ($exists === false) {
            self::emitInvalidArgumentName($event, $name, $commandClass);

            return null;
        }

        $argument = CommandDefinitionAnalyzer::getArgument($commandClass, $name);

        if (!$argument instanceof \Symfony\Component\Console\Input\InputArgument) {
            return null;
        }

        return self::argumentToType($argument);
    }

    /**
     * @param class-string $commandClass
     */
    private static function resolveOptionType(
        string $commandClass,
        string $name,
        MethodReturnTypeProviderEvent $event,
    ): ?Type\Union {
        $exists = CommandDefinitionAnalyzer::hasOption($commandClass, $name);

        // Definition unavailable — cannot narrow
        if ($exists === null) {
            return null;
        }

        if ($exists === false) {
            self::emitInvalidOptionName($event, $name, $commandClass);

            return null;
        }

        $option = CommandDefinitionAnalyzer::getOption($commandClass, $name);

        if (!$option instanceof \Symfony\Component\Console\Input\InputOption) {
            return null;
        }

        return self::optionToType($option);
    }

    /**
     * Map an InputArgument to its narrowed Psalm type.
     *
     * Array argument ({name*})        → array<int, string>
     * Required scalar ({name})        → string
     * Optional scalar ({name?})       → string|null
     */
    private static function argumentToType(InputArgument $argument): Type\Union
    {
        if ($argument->isArray()) {
            return new Type\Union([
                new Type\Atomic\TArray([Type::getInt(), Type::getString()]),
            ]);
        }

        if ($argument->isRequired()) {
            return Type::getString();
        }

        // Optional scalar with a non-null default — always returns string
        if ($argument->getDefault() !== null) {
            return Type::getString();
        }

        // Optional scalar without default — may be null when not provided
        return Type::combineUnionTypes(Type::getString(), Type::getNull());
    }

    /**
     * Map an InputOption to its narrowed Psalm type.
     *
     * No-value flag ({--flag})            → bool
     * Negatable flag                      → bool|null
     * Value-accepting ({--opt=})          → string|null
     * Array option ({--opt=*})            → array<int, string>
     *
     * Note: Laravel's signature parser always produces VALUE_OPTIONAL for {--opt=} syntax,
     * never VALUE_REQUIRED. At runtime, VALUE_OPTIONAL returns string when provided with
     * a value, or null when absent — no bool is involved.
     */
    private static function optionToType(InputOption $option): Type\Union
    {
        // Array options always return array<int, string>
        if ($option->isArray()) {
            return new Type\Union([
                new Type\Atomic\TArray([Type::getInt(), Type::getString()]),
            ]);
        }

        // Negatable flags (--flag/--no-flag): true, false, or null (when neither passed)
        if ($option->isNegatable()) {
            return Type::combineUnionTypes(Type::getBool(), Type::getNull());
        }

        // No-value flag (VALUE_NONE): always bool (false when absent, true when present)
        if (!$option->acceptValue()) {
            return Type::getBool();
        }

        // Value-accepting option (VALUE_OPTIONAL or VALUE_REQUIRED):
        // string when provided with a value, null when absent
        return Type::combineUnionTypes(Type::getString(), Type::getNull());
    }

    /**
     * @param class-string $commandClass
     */
    private static function emitInvalidArgumentName(
        MethodReturnTypeProviderEvent $event,
        string $name,
        string $commandClass,
    ): void {
        IssueBuffer::accepts(
            new InvalidConsoleArgumentName(
                "Argument '{$name}' is not defined in " . self::shortClassName($commandClass) . "'s signature",
                new CodeLocation($event->getSource(), $event->getStmt()),
            ),
            $event->getSource()->getSuppressedIssues(),
        );
    }

    /**
     * @param class-string $commandClass
     */
    private static function emitInvalidOptionName(
        MethodReturnTypeProviderEvent $event,
        string $name,
        string $commandClass,
    ): void {
        IssueBuffer::accepts(
            new InvalidConsoleOptionName(
                "Option '{$name}' is not defined in " . self::shortClassName($commandClass) . "'s signature",
                new CodeLocation($event->getSource(), $event->getStmt()),
            ),
            $event->getSource()->getSuppressedIssues(),
        );
    }

    /**
     * @param class-string $commandClass
     * @psalm-pure
     */
    private static function shortClassName(string $commandClass): string
    {
        return \str_contains($commandClass, '\\')
            ? \substr($commandClass, (int) \strrpos($commandClass, '\\') + 1)
            : $commandClass;
    }
}
