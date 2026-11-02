<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Config;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;
use Symfony\Component\Finder\Finder;

/**
 * Maps a dot-notation config key to the config-file expression that defines it, mirroring how
 * the loader that booted the app assembled the repository. A key it cannot attribute with
 * certainty has no source. Files are parsed lazily, once each.
 *
 * @internal
 */
final class ConfigSourceIndex
{
    /** {@see LoadConfiguration::mergeableOptions()} */
    private const MERGEABLE_OPTIONS = [
        'auth' => ['guards', 'providers', 'passwords'],
        'broadcasting' => ['connections'],
        'cache' => ['stores'],
        'database' => ['connections'],
        'filesystems' => ['disks'],
        'logging' => ['channels'],
        'mail' => ['mailers'],
        'queue' => ['connections'],
    ];

    /** @var array<string, Expr\Array_|false> parsed root per path, false when opaque */
    private array $roots = [];

    private ?Parser $parser = null;

    /**
     * @param array<array-key, string|false> $files app config file key => path, false when the
     *        loaded file may come from a directory that is not indexed
     * @param array<array-key, string> $baseFiles framework base config name => path
     * @psalm-mutation-free
     */
    private function __construct(
        private array $files,
        private readonly array $baseFiles,
    ) {}

    public static function fromApp(Application $app): self
    {
        $loader = (new \ReflectionClass(LoadConfiguration::class))->getFileName();
        $frameworkConfig = $loader === false ? null : \dirname($loader, 5) . '/config';

        if (ApplicationProvider::getBootMode() === 'testbench_fallback') {
            // Testbench's own loader read basePath('config'); configPath() was retargeted after
            // loading. Its framework-config merge is not modelled: leaving the base out only drops
            // sources, while modelling a different merge could misattribute a value.
            $files = self::scan($app->basePath('config'), true);

            // A workbench config file replaces the skeleton's when Testbench discovers it.
            if (\function_exists('Orchestra\Testbench\workbench_path')) {
                foreach (\array_keys(self::scan(\Orchestra\Testbench\workbench_path('config'), true)) as $key) {
                    $files[$key] = false;
                }
            }

            // That merge also sets the framework config after the app files (non-default skeletons).
            $frameworkNames = $frameworkConfig === null ? [] : \array_keys(self::scan($frameworkConfig, false));

            return new self(self::withoutReplaced($files, $frameworkNames), []);
        }

        // A rebound loader may read config from anywhere.
        if ($app->bound(LoadConfiguration::class)) {
            return self::fromDirectories(null);
        }

        return self::fromDirectories(
            $app->configPath(),
            $app->shouldMergeFrameworkConfiguration() ? $frameworkConfig : null,
        );
    }

    /** @param string|null $baseDir framework config merged under the app's, as LoadConfiguration does */
    public static function fromDirectories(?string $configDir, ?string $baseDir = null): self
    {
        $baseFiles = $baseDir === null ? [] : self::scan($baseDir, false);

        return new self(
            self::withoutReplaced($configDir === null ? [] : self::scan($configDir, true), \array_keys($baseFiles)),
            $baseFiles,
        );
    }

    public function find(string $key): ?ConfigSource
    {
        $segments = \explode('.', $key);

        // config/foo/bar.php defines `foo.bar` over config/foo.php, so the longest file key wins.
        for ($length = \count($segments); $length > 0; $length--) {
            $fileKey = \implode('.', \array_slice($segments, 0, $length));

            if ($this->hasFile($fileKey)) {
                $source = $this->root($fileKey);
                foreach (\array_slice($segments, $length) as $segment) {
                    $source = $source?->child($segment);
                }

                return $source;
            }
        }

        return null;
    }

    /** @psalm-mutation-free */
    public function hasFile(string $fileKey): bool
    {
        return \array_key_exists($fileKey, $this->files) || \array_key_exists($fileKey, $this->baseFiles);
    }

    public function root(string $fileKey): ?ConfigSource
    {
        $layers = [];
        foreach ([$this->files[$fileKey] ?? null, $this->baseFiles[$fileKey] ?? null] as $path) {
            if ($path === null) {
                continue;
            }

            $root = $path === false ? null : $this->parse($path);

            if (!$root instanceof Expr\Array_) {
                return null;
            }

            $layers[] = $root;
        }

        if ($layers === []) {
            return null;
        }

        return new ConfigSource($this, $fileKey, $layers, \count($layers) > 1 ? self::MERGEABLE_OPTIONS[$fileKey] ?? [] : []);
    }

    /**
     * The loader sets a framework config that no app file of the same name consumed after all
     * app files, replacing whatever nested app files (`config/services/postmark.php`) set below it.
     *
     * @template T of string|false
     * @param array<array-key, T> $files
     * @param list<array-key> $frameworkNames
     * @return array<array-key, T>
     * @psalm-pure
     */
    private static function withoutReplaced(array $files, array $frameworkNames): array
    {
        foreach (\array_keys($files) as $key) {
            $top = \explode('.', (string) $key, 2)[0];

            if (\in_array($top, $frameworkNames, true) && !\array_key_exists($top, $files)) {
                unset($files[$key]);
            }
        }

        return $files;
    }

    /**
     * Items keyed as PHP stores them. Null unless every key is a literal (no spread,
     * computed key, by-ref item or negative integer key).
     *
     * @return array<array-key, Expr>|null
     * @psalm-mutation-free
     */
    public static function literalItems(Expr $node): ?array
    {
        if (!$node instanceof Expr\Array_) {
            return null;
        }

        $items = [];
        $nextIndex = 0;
        foreach ($node->items as $item) {
            if ($item->unpack || $item->byRef) {
                return null;
            }

            $key = match (true) {
                $item->key === null => $nextIndex,
                $item->key instanceof Node\Scalar\Int_ => $item->key->value,
                $item->key instanceof Node\Scalar\String_ => (string) (int) $item->key->value === $item->key->value
                    ? (int) $item->key->value
                    : $item->key->value,
                // NameResolver fully qualifies every class name but self, static and parent.
                $item->key instanceof Expr\ClassConstFetch && $item->key->class instanceof Node\Name\FullyQualified
                    && $item->key->name instanceof Node\Identifier && \strtolower($item->key->name->name) === 'class' => $item->key->class->toString(),
                default => null,
            };

            if ($key === null) {
                return null;
            }

            if (\is_int($key)) {
                // PHP 8.3 changed the next index after a negative key.
                if ($key < 0) {
                    return null;
                }

                $nextIndex = \max($nextIndex, $key + 1);
            }

            $items[$key] = $item->value;
        }

        return $items;
    }

    private function parse(string $path): ?Expr\Array_
    {
        if (!\array_key_exists($path, $this->roots)) {
            $this->roots[$path] = $this->parseRoot($path, $this->parser ??= (new ParserFactory())->createForNewestSupportedVersion()) ?? false;
        }

        return $this->roots[$path] ?: null;
    }

    /** The array of the file's single, unconditional top-level `return`; null otherwise. */
    private function parseRoot(string $path, Parser $parser): ?Expr\Array_
    {
        $returns = new class extends NodeVisitorAbstract {
            /** @var list<Node\Stmt\Return_> */
            public array $found = [];

            /** @psalm-external-mutation-free */
            #[\Override]
            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Node\Stmt\Return_) {
                    $this->found[] = $node;
                }

                return null;
            }
        };

        try {
            $code = \file_get_contents($path);
            $statements = $code === false ? null : $parser->parse($code);

            if ($statements === null) {
                return null;
            }

            // Separate passes: skipping function bodies must not leave the names in them unresolved.
            (new NodeTraverser(new NameResolver()))->traverse($statements);
            (new NodeTraverser($returns))->traverse($statements);

            $topLevel = \array_merge(...\array_values(\array_map(
                static fn(Node\Stmt $statement): array => $statement instanceof Node\Stmt\Namespace_ ? $statement->stmts : [$statement],
                $statements,
            )));

            $return = \count($returns->found) === 1 ? $returns->found[0] : null;
            $root = $return?->expr;

            if (!$root instanceof Expr\Array_ || !\in_array($return, $topLevel, true)) {
                return null;
            }

            // Any statement besides the return may assign a variable from env, also through a function
            // it declares or a file it includes. In the return, a closure stored as a config value never
            // runs while the file loads, but anything else may: an include, or an assignment or a call that
            // sees a variable (a by-reference argument, a mutated object, an invoked body writing what it captured).
            $finder = new NodeFinder();
            $executed = [];
            $arrays = [$root];
            while ($array = \array_pop($arrays)) {
                foreach ($array->items as $item) {
                    if ($item->value instanceof Expr\Array_) {
                        $arrays[] = $item->value;
                    } elseif (!$item->value instanceof Expr\Closure && !$item->value instanceof Expr\ArrowFunction) {
                        $executed[] = $item->value;
                    }

                    if ($item->key instanceof Expr) {
                        $executed[] = $item->key;
                    }
                }
            }

            $writes = $finder->find($executed, static fn(Node $node): bool => $node instanceof Expr\Include_
                || ($node instanceof Expr\Assign || $node instanceof Expr\AssignRef || $node instanceof Expr\AssignOp || $node instanceof Expr\CallLike)
                && $finder->findFirstInstanceOf($node, Expr\Variable::class) instanceof Node);
            $scanned = [...\array_filter($topLevel, static fn(Node\Stmt $statement): bool => $statement !== $return), ...$writes];
            if ($finder->findFirst($scanned, EnvExpressionTyper::mayReadEnv(...)) instanceof Node) {
                foreach ($finder->findInstanceOf($root, Expr\Variable::class) as $variable) {
                    $variable->setAttribute(EnvExpressionTyper::MAY_HOLD_ENV, true);
                }
            }

            return $root;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Config file key => real path, found the way LoadConfiguration's Finder finds them. A
     * top-level file with a numeric name gets an integer key, as in the repository.
     *
     * @return array<array-key, string>
     */
    private static function scan(string $directory, bool $nested): array
    {
        $root = \realpath($directory);

        if ($root === false || !\is_dir($root)) {
            return [];
        }

        $files = [];
        foreach (Finder::create()->files()->name('*.php')->in($root) as $file) {
            $path = $file->getRealPath();

            if ($path === false) {
                continue;
            }

            $prefix = $nested ? \trim(\str_replace($root, '', $file->getPath()), \DIRECTORY_SEPARATOR) : '';
            $prefix = $prefix === '' ? '' : \str_replace(\DIRECTORY_SEPARATOR, '.', $prefix) . '.';

            $files[$prefix . \basename($path, '.php')] = $path;
        }

        return $files;
    }
}
