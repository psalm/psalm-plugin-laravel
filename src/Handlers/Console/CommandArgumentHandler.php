<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Console;

use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Issues\UndefinedConsoleInput;
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
 * @see \Illuminate\Console\Concerns\InteractsWithIO::arguments()
 * @see \Illuminate\Console\Concerns\InteractsWithIO::options()
 *
 * Also emits {@see UndefinedConsoleInput} when the requested name is not defined.
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

        if (!\in_array($methodName, ['argument', 'option', 'arguments', 'options'], true)) {
            return null;
        }

        // arguments() and options() (no-arg variants) — fall through to Psalm's default
        if ($methodName === 'arguments' || $methodName === 'options') {
            return null;
        }

        // argument(null) / option(null) or no args — returns the full array, fall through
        $callArgs = $event->getCallArgs();

        if ($callArgs === []) {
            return null;
        }

        // Extract the literal string key from the first argument
        $firstArgType = $event->getSource()->getNodeTypeProvider()->getType($callArgs[0]->value);

        if ($firstArgType === null || !$firstArgType->isSingleStringLiteral()) {
            return null; // dynamic key — cannot narrow
        }

        $key = $firstArgType->getSingleStringLiteral()->value;

        // Determine the concrete command class being analyzed.
        // getCalledFqClasslikeName() returns the concrete subclass (e.g., App\Console\Commands\Example),
        // getFqClasslikeName() returns the class the handler is hooked to (Illuminate\Console\Command).
        /** @var class-string|null $commandClass */
        $commandClass = $event->getCalledFqClasslikeName();

        if ($commandClass === null) {
            return null;
        }

        if ($methodName === 'argument') {
            return self::resolveArgumentType($commandClass, $key, $event);
        }

        return self::resolveOptionType($commandClass, $key, $event);
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
            self::emitUndefinedIssue($event, 'argument', $name, $commandClass);

            return null;
        }

        $argument = CommandDefinitionAnalyzer::getArgument($commandClass, $name);

        if ($argument === null) {
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
            self::emitUndefinedIssue($event, 'option', $name, $commandClass);

            return null;
        }

        $option = CommandDefinitionAnalyzer::getOption($commandClass, $name);

        if ($option === null) {
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
    private static function emitUndefinedIssue(
        MethodReturnTypeProviderEvent $event,
        string $kind,
        string $name,
        string $commandClass,
    ): void {
        $shortClass = \str_contains($commandClass, '\\')
            ? \substr($commandClass, (int) \strrpos($commandClass, '\\') + 1)
            : $commandClass;

        IssueBuffer::accepts(
            new UndefinedConsoleInput(
                "Console {$kind} '{$name}' is not defined in {$shortClass}'s signature",
                new CodeLocation($event->getSource(), $event->getStmt()),
            ),
            $event->getSource()->getSuppressedIssues(),
        );
    }
}
