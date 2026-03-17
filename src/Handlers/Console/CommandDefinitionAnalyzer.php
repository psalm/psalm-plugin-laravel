<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Console;

use Illuminate\Console\Parser;
use PhpParser\Node;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * Resolves Artisan command input definitions by reading the $signature property
 * from the analyzed source's AST and parsing it with Laravel's {@see Parser}.
 *
 * This works statically — the analyzed command class does not need to be loaded
 * into PHP's runtime. We read the literal string default value of the $signature
 * property from the AST and parse it with Laravel's own signature parser.
 *
 * Cached per command class — each signature is parsed at most once per Psalm run.
 */
final class CommandDefinitionAnalyzer
{
    /** @var array<string, InputDefinition|null> */
    private static array $cache = [];

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
     * Extract the $signature property's literal string default value from the AST.
     *
     * Walks up the class hierarchy (child → parent) to find the first class
     * that declares $signature with a string literal default.
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

        while ($currentClass !== '') {
            try {
                $storage = $codebase->classlike_storage_provider->get($currentClass);
            } catch (\InvalidArgumentException) {
                break;
            }

            // Only check this class if it declares the $signature property
            if (!isset($storage->properties['signature'])) {
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

            $value = self::findSignatureDefaultInStatements($statements, $storage->name);

            if ($value !== null) {
                return $value;
            }

            // Move to parent class
            $currentClass = \strtolower($storage->parent_class ?? '');
        }

        return null;
    }

    /**
     * Walk AST statements to find the $signature property default in the given class.
     *
     * @param list<Node\Stmt> $statements
     * @psalm-mutation-free
     */
    private static function findSignatureDefaultInStatements(array $statements, string $className): ?string
    {
        foreach ($statements as $stmt) {
            // Track namespace for building FQCN
            if ($stmt instanceof Node\Stmt\Namespace_) {
                $result = self::findSignatureInNamespace($stmt, $className);

                if ($result !== null) {
                    return $result;
                }

                continue;
            }

            if (!$stmt instanceof Node\Stmt\Class_) {
                continue;
            }

            // Top-level class (no namespace)
            $shortName = $stmt->name?->toString() ?? '';

            if (\strtolower($shortName) !== \strtolower($className)) {
                continue;
            }

            return self::findSignaturePropertyDefault($stmt);
        }

        return null;
    }

    /**
     * Search within a namespace statement for the target class's $signature default.
     *
     * @psalm-mutation-free
     */
    private static function findSignatureInNamespace(Node\Stmt\Namespace_ $namespace, string $className): ?string
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

            return self::findSignaturePropertyDefault($stmt);
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
        if ($expr === null) {
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

        if ($definition === null) {
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

        if ($definition === null) {
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

        if ($definition === null) {
            return null; // cannot determine — definition unavailable
        }

        return $definition->hasArgument($name);
    }

    /**
     * Check if an option exists in the command's definition.
     *
     * Also checks option shortcuts (e.g., 'F' for {--F|force}).
     *
     * @param class-string $commandClass
     */
    public static function hasOption(string $commandClass, string $name): ?bool
    {
        $definition = self::getDefinition($commandClass);

        if ($definition === null) {
            return null; // cannot determine — definition unavailable
        }

        if ($definition->hasOption($name)) {
            return true;
        }

        // Check if the name matches a shortcut
        return $definition->hasShortcut($name);
    }
}
