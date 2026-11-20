<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Console;

use Illuminate\Console\Parser;
use PhpParser\Node;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Storage\ClassLikeStorage;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * Resolves Artisan command input definitions by reading the #[Signature] attribute or the
 * $signature property from the analyzed source's AST and parsing it with Laravel's {@see Parser}.
 *
 * This works statically — the analyzed command class does not need to be loaded
 * into PHP's runtime. We read the literal string from the AST and parse it with
 * Laravel's own signature parser.
 *
 * Cached per command class — each signature is parsed at most once per Psalm run.
 */
final class CommandDefinitionAnalyzer
{
    /** Laravel 13+; a string, so the plugin still loads against Laravel 12. */
    private const SIGNATURE_ATTRIBUTE = 'Illuminate\Console\Attributes\Signature';

    /** @var array<string, InputDefinition|null> */
    private static array $cache = [];

    public static function reset(): void
    {
        self::$cache = [];
    }

    /**
     * Get the InputDefinition for a command class by reading its $signature from the AST.
     *
     * @param class-string $commandClass
     */
    public static function getDefinition(string $commandClass): ?InputDefinition
    {
        $cacheKey = \strtolower($commandClass);

        if (\array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $signature = self::extractSignature($commandClass);

        if ($signature === null) {
            return self::$cache[$cacheKey] = null;
        }

        try {
            // Parse the signature using Laravel's own parser.
            // Returns: [name, InputArgument[], InputOption[]]
            /** @var array{0: string, 1: list<InputArgument>, 2: list<InputOption>} $parsed */
            $parsed = Parser::parse($signature);

            $definition = new InputDefinition();
            $definition->addArguments($parsed[1]);
            $definition->addOptions($parsed[2]);

            // Add global options inherited from Symfony Application and Laravel.
            // Every Artisan command inherits these — they don't appear in $signature
            // but are valid targets for $this->option('verbose'), etc.
            self::addGlobalOptions($definition);

            return self::$cache[$cacheKey] = $definition;
        } catch (\InvalidArgumentException|LogicException) {
            // Invalid or malformed signature — gracefully degrade
            return self::$cache[$cacheKey] = null;
        }
    }

    /**
     * Add global options that every Artisan command inherits from Symfony Application
     * and Laravel's Application override. These are always available but don't appear
     * in the command's $signature.
     *
     * @see \Symfony\Component\Console\Application::getDefaultInputDefinition()
     * @see \Illuminate\Console\Application::getDefaultInputDefinition()
     */
    private static function addGlobalOptions(InputDefinition $definition): void
    {
        $globalOptions = [
            new InputOption('help', 'h', InputOption::VALUE_NONE),
            new InputOption('quiet', 'q', InputOption::VALUE_NONE),
            new InputOption('silent', null, InputOption::VALUE_NONE),
            new InputOption('verbose', 'v', InputOption::VALUE_NONE),
            new InputOption('version', 'V', InputOption::VALUE_NONE),
            new InputOption('ansi', '', InputOption::VALUE_NEGATABLE),
            new InputOption('no-interaction', 'n', InputOption::VALUE_NONE),
            new InputOption('env', null, InputOption::VALUE_OPTIONAL),
        ];

        foreach ($globalOptions as $option) {
            if (!$definition->hasOption($option->getName())) {
                $definition->addOption($option);
            }
        }
    }

    /**
     * Extract the command's signature string from the AST.
     *
     * Mirrors Command::__construct(): a #[Signature] attribute on the concrete class wins
     * (configureFromAttributes() reflects only static::class, so a parent's attribute is
     * ignored), otherwise the first class up the hierarchy declaring a literal $signature.
     *
     * @param class-string $commandClass
     */
    private static function extractSignature(string $commandClass): ?string
    {
        try {
            $codebase = ProjectAnalyzer::getInstance()->getCodebase();
        } catch (\RuntimeException|\Error) {
            // RuntimeException: no instance yet. Error: uninitialized typed static property.
            return null;
        }

        // Walk the class hierarchy to find $signature's literal value
        $currentClass = \strtolower($commandClass);
        $isConcreteClass = true;

        while ($currentClass !== '') {
            try {
                $storage = $codebase->classlike_storage_provider->get($currentClass);
            } catch (\InvalidArgumentException) {
                break;
            }

            $readAttribute = $isConcreteClass && self::declaresSignatureAttribute($storage);
            $isConcreteClass = false;

            // Only check this class if it carries the attribute or declares the $signature property
            if (!$readAttribute && !isset($storage->properties['signature'])) {
                $currentClass = \strtolower($storage->parent_class ?? '');

                continue;
            }

            // Get the file path where this class is defined
            $filePath = $storage->location?->file_path;

            if ($filePath === null) {
                $currentClass = \strtolower($storage->parent_class ?? '');

                continue;
            }

            // Read the AST for this file and find the $signature property's default
            try {
                $statements = $codebase->getStatementsForFile($filePath);
            } catch (\InvalidArgumentException|\UnexpectedValueException) {
                $currentClass = \strtolower($storage->parent_class ?? '');

                continue;
            }

            $value = self::findSignatureDefaultInStatements($statements, $storage->name, $readAttribute);

            // An unresolvable #[Signature] still overrides any inherited $signature, so decline
            if ($value !== null || $readAttribute) {
                return $value;
            }

            // Move to parent class
            $currentClass = \strtolower($storage->parent_class ?? '');
        }

        return null;
    }

    /** @psalm-mutation-free */
    private static function declaresSignatureAttribute(ClassLikeStorage $storage): bool
    {
        foreach ($storage->attributes as $attribute) {
            if (\strtolower($attribute->fq_class_name) === \strtolower(self::SIGNATURE_ATTRIBUTE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Walk AST statements to find the signature of the given class.
     *
     * Not mutation-free: reading an attribute's `resolvedName` goes through the unannotated Node::getAttribute().
     *
     * @param list<Node\Stmt> $statements
     */
    private static function findSignatureDefaultInStatements(array $statements, string $className, bool $readAttribute): ?string
    {
        foreach ($statements as $stmt) {
            // Track namespace for building FQCN
            if ($stmt instanceof Node\Stmt\Namespace_) {
                $result = self::findSignatureInNamespace($stmt, $className, $readAttribute);

                if ($result !== null) {
                    return $result;
                }

                continue;
            }

            if (!$stmt instanceof Node\Stmt\Class_) {
                continue;
            }

            // Top-level class (no namespace) — $className is an FQCN from storage,
            // so extract the short name for comparison with the AST node.
            $shortName = $stmt->name?->toString() ?? '';
            $classShortName = \str_contains($className, '\\')
                ? \substr($className, (int) \strrpos($className, '\\') + 1)
                : $className;

            if (\strtolower($shortName) !== \strtolower($classShortName)) {
                continue;
            }

            return self::findSignatureInClass($stmt, $readAttribute);
        }

        return null;
    }

    /** Search within a namespace statement for the target class's signature. */
    private static function findSignatureInNamespace(Node\Stmt\Namespace_ $namespace, string $className, bool $readAttribute): ?string
    {
        $namespaceName = $namespace->name?->toString() ?? '';

        foreach ($namespace->stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\Class_) {
                continue;
            }

            $shortName = $stmt->name?->toString() ?? '';
            $fqcn = $namespaceName !== '' ? $namespaceName . '\\' . $shortName : $shortName;

            if (\strtolower($fqcn) !== \strtolower($className)) {
                continue;
            }

            return self::findSignatureInClass($stmt, $readAttribute);
        }

        return null;
    }

    private static function findSignatureInClass(Node\Stmt\Class_ $class, bool $readAttribute): ?string
    {
        if ($readAttribute) {
            return self::findSignatureAttributeValue($class);
        }

        return self::findSignaturePropertyDefault($class);
    }

    /** Read the signature argument (positional or named) of the class's #[Signature] attribute. */
    private static function findSignatureAttributeValue(Node\Stmt\Class_ $class): ?string
    {
        foreach ($class->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                /** @psalm-var string|null $resolved */
                $resolved = $attr->name->getAttribute('resolvedName');
                $attributeClass = \ltrim($resolved ?? $attr->name->toString(), '\\');

                if (\strtolower($attributeClass) !== \strtolower(self::SIGNATURE_ATTRIBUTE)) {
                    continue;
                }

                foreach ($attr->args as $position => $arg) {
                    $isSignatureArg = $arg->name instanceof Node\Identifier
                        ? $arg->name->toString() === 'signature'
                        : $position === 0;

                    if ($isSignatureArg) {
                        return self::resolveStringValue($arg->value);
                    }
                }

                return null;
            }
        }

        return null;
    }

    /**
     * Find the $signature property's default value in a class statement.
     *
     * @psalm-mutation-free
     */
    private static function findSignaturePropertyDefault(Node\Stmt\Class_ $class): ?string
    {
        foreach ($class->stmts as $classStmt) {
            if (!$classStmt instanceof Node\Stmt\Property) {
                continue;
            }

            foreach ($classStmt->props as $prop) {
                if ($prop->name->toString() !== 'signature') {
                    continue;
                }

                return self::resolveStringValue($prop->default);
            }
        }

        return null;
    }

    /**
     * Resolve a PhpParser node to a plain string, supporting string literals
     * and concatenation expressions.
     *
     * For concatenation with non-resolvable parts (class constants, function calls, etc.),
     * the unresolvable part is replaced with a placeholder. This works because Laravel's
     * Parser only cares about {argument} and {--option} tokens — description text
     * containing constants (e.g., 'default: v' . SomeClass::LATEST . ')') is ignored.
     *
     * @psalm-mutation-free
     */
    private static function resolveStringValue(?Node\Expr $expr): ?string
    {
        if (!$expr instanceof \PhpParser\Node\Expr) {
            return null;
        }

        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        // Support heredoc/nowdoc
        if ($expr instanceof Node\Scalar\InterpolatedString && $expr->parts !== []) {
            $result = '';
            foreach ($expr->parts as $part) {
                if ($part instanceof Node\InterpolatedStringPart) {
                    $result .= $part->value;
                } else {
                    return null; // Contains variables — can't resolve statically
                }
            }

            return $result;
        }

        // Support concatenation: 'part1' . 'part2' . SomeClass::CONST
        // Non-resolvable parts (constants, function calls) are replaced with a placeholder
        // so the signature's {argument}/{--option} tokens can still be parsed.
        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            $left = self::resolveStringValue($expr->left) ?? '___';
            $right = self::resolveStringValue($expr->right) ?? '___';

            return $left . $right;
        }

        return null;
    }

    /**
     * Look up an argument by name from a command's definition.
     *
     * @param class-string $commandClass
     */
    public static function getArgument(string $commandClass, string $name): ?InputArgument
    {
        $definition = self::getDefinition($commandClass);

        if (!$definition instanceof \Symfony\Component\Console\Input\InputDefinition) {
            return null;
        }

        try {
            return $definition->getArgument($name);
        } catch (\Symfony\Component\Console\Exception\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Look up an option by name or shortcut from a command's definition.
     *
     * Supports both long names (e.g., 'force') and shortcuts (e.g., 'F' for {--F|force}).
     *
     * @param class-string $commandClass
     */
    public static function getOption(string $commandClass, string $name): ?InputOption
    {
        $definition = self::getDefinition($commandClass);

        if (!$definition instanceof \Symfony\Component\Console\Input\InputDefinition) {
            return null;
        }

        try {
            return $definition->getOption($name);
        } catch (\Symfony\Component\Console\Exception\InvalidArgumentException) {
            // Fall through to try shortcut resolution
        }

        // Try resolving as a shortcut (e.g., 'F' for {--F|force})
        try {
            return $definition->getOptionForShortcut($name);
        } catch (\Symfony\Component\Console\Exception\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Check if an argument exists in the command's definition.
     *
     * @param class-string $commandClass
     */
    public static function hasArgument(string $commandClass, string $name): ?bool
    {
        $definition = self::getDefinition($commandClass);

        if (!$definition instanceof \Symfony\Component\Console\Input\InputDefinition) {
            return null; // cannot determine — definition unavailable
        }

        return $definition->hasArgument($name);
    }

    /**
     * Check if an option exists in the command's definition.
     *
     * Mirrors Symfony's Input::hasOption() — a name matches a long option OR a negation
     * (e.g. 'no-ansi' for the negatable '--ansi'). Shortcuts are NOT valid hasOption()
     * names: Input::hasOption('F') is false even when -F is a defined shortcut.
     *
     * @param class-string $commandClass
     */
    public static function hasOption(string $commandClass, string $name): ?bool
    {
        $definition = self::getDefinition($commandClass);

        if (!$definition instanceof \Symfony\Component\Console\Input\InputDefinition) {
            return null; // cannot determine — definition unavailable
        }

        return $definition->hasOption($name) || $definition->hasNegation($name);
    }
}
