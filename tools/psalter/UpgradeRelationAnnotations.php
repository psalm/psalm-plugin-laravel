<?php

declare(strict_types=1);

use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use Psalm\FileManipulation;
use Psalm\Plugin\EventHandler\AfterFunctionLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterFunctionLikeAnalysisEvent;

/**
 * Psalter plugin that upgrades Eloquent relation PHPDoc annotations from
 * psalm-plugin-laravel v3 to v4.
 *
 * v4 adds a TDeclaringModel template parameter to all relation return types.
 * Run this plugin once to patch @return / @psalm-return annotations across
 * your codebase automatically.
 *
 * Usage (run from your project root):
 *
 *   # Preview changes without writing files:
 *   vendor/bin/psalter --plugin=vendor/psalm/plugin-laravel/tools/psalter/UpgradeRelationAnnotations.php --dry-run
 *
 *   # Apply changes:
 *   vendor/bin/psalter --plugin=vendor/psalm/plugin-laravel/tools/psalter/UpgradeRelationAnnotations.php
 *
 * What is fixed automatically:
 *
 *   @return BelongsTo<User>      → @return BelongsTo<User, self>
 *   @return HasMany<Post>        → @return HasMany<Post, self>
 *   @return HasOne<Profile>      → @return HasOne<Profile, self>
 *   @return MorphOne<Image>      → @return MorphOne<Image, self>
 *   @return MorphMany<Tag>       → @return MorphMany<Tag, self>
 *   @return MorphTo<Model>       → @return MorphTo<Model, self>
 *
 *   BelongsToMany and MorphToMany require all 4 template params because Psalm
 *   does not honour template-param defaults. The plugin appends the default
 *   pivot class and accessor when they are missing:
 *
 *   @return BelongsToMany<Tag>        → @return BelongsToMany<Tag, self, \Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>
 *   @return BelongsToMany<Tag, self>  → @return BelongsToMany<Tag, self, \Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>
 *   @return MorphToMany<Tag>          → @return MorphToMany<Tag, self, \Illuminate\Database\Eloquent\Relations\MorphPivot, 'pivot'>
 *   @return MorphToMany<Tag, self>    → @return MorphToMany<Tag, self, \Illuminate\Database\Eloquent\Relations\MorphPivot, 'pivot'>
 *
 *   HasManyThrough and HasOneThrough require an intermediate model that cannot
 *   be inferred from the docblock alone. The plugin reads the method body to
 *   extract the through-model from the hasManyThrough()/hasOneThrough() call:
 *
 *   @return HasManyThrough<Post>  → @return HasManyThrough<Post, \App\Models\Country, self>
 *   @return HasOneThrough<Phone>  → @return HasOneThrough<Phone, \App\Models\User, self>
 *
 *   A warning is emitted to STDERR for any through relation whose intermediate
 *   model cannot be resolved (dynamic call, variable argument, etc.).
 *
 * Both @return and @psalm-return annotations are handled.
 * Fully-qualified names (e.g. \Illuminate\...\BelongsTo<User>) are also handled.
 * Annotations that already have the correct number of params are left unchanged.
 */
final class UpgradeRelationAnnotations implements AfterFunctionLikeAnalysisInterface
{
    /**
     * Relations where TDeclaringModel becomes the second (and final) type parameter.
     *
     * @var list<string>
     */
    private const AUTO_RELATIONS = [
        'BelongsTo',
        'HasMany',
        'HasOne',
        'MorphMany',
        'MorphOne',
        'MorphTo',
    ];

    /**
     * Relations that require all 4 type parameters because Psalm does not honour
     * template-param defaults. Maps relation name → fully-qualified default pivot class.
     *
     * @var array<string, string>
     */
    private const FOUR_PARAM_RELATIONS = [
        'BelongsToMany' => '\Illuminate\Database\Eloquent\Relations\Pivot',
        'MorphToMany'   => '\Illuminate\Database\Eloquent\Relations\MorphPivot',
    ];

    /**
     * Relations that need an intermediate model extracted from the method body.
     * Maps relation type name → the Eloquent method call that builds it.
     * The through/intermediate model is always the second argument of that call.
     *
     * @var array<string, string>
     */
    private const THROUGH_RELATIONS = [
        'HasManyThrough' => 'hasManyThrough',
        'HasOneThrough'  => 'hasOneThrough',
    ];

    /**
     * Tracks locations already warned to avoid duplicate STDERR output
     * when Psalm re-analyzes the same method in a different context pass.
     *
     * @var array<string, true>
     */
    private static array $warned = [];

    public static function afterStatementAnalysis(AfterFunctionLikeAnalysisEvent $event): ?bool
    {
        $stmt = $event->getStmt();
        $docComment = $stmt->getDocComment();

        if ($docComment === null) {
            return null;
        }

        $originalText = $docComment->getText();
        $updatedText = self::upgradeDocblock($originalText);

        // Only warn (and write files) when Psalter is running in alter mode.
        // During a normal `psalm` run, alter_code is false and we skip both
        // the warning output and the file manipulation to avoid side effects.
        if ($event->getCodebase()->alter_code) {
            // Attempt AST-based fix for HasManyThrough / HasOneThrough by reading
            // the method body to find the intermediate model. Must run before the
            // warning check so the warning is only emitted for truly unresolvable cases.
            $updatedText = self::upgradeThroughRelations($updatedText, $stmt, $event);

            self::warnIfManualMigrationNeeded($updatedText, $stmt, $event);

            if ($updatedText !== $originalText) {
                // getEndFilePos() returns the inclusive offset of the last character ('/')
                // in the closing '*/'. We add 1 to get an exclusive end for FileManipulation.
                $replacements = $event->getFileReplacements();
                $replacements[] = new FileManipulation(
                    $docComment->getStartFilePos(),
                    $docComment->getEndFilePos() + 1,
                    $updatedText,
                );
                $event->setFileReplacements($replacements);
            }
        }

        return null;
    }

    /**
     * Apply all v3 → v4 docblock-only transformations to a raw docblock string.
     *
     * These transforms require only the docblock text (no AST access):
     * - AUTO_RELATIONS: add TDeclaringModel = self as the second param
     * - FOUR_PARAM_RELATIONS: add TDeclaringModel + pivot defaults
     *
     * HasManyThrough / HasOneThrough are NOT handled here — they need the method
     * body to determine the intermediate model. See upgradeThroughRelations().
     *
     * Extracted as a public static method so it can be unit-tested
     * without a running Psalm analysis environment.
     */
    public static function upgradeDocblock(string $docblock): string
    {
        $lines = \explode("\n", $docblock);

        foreach ($lines as &$line) {
            // Only process @return and @psalm-return annotation lines.
            if (!\preg_match('/@(?:psalm-)?return\b/', $line)) {
                continue;
            }

            foreach (self::AUTO_RELATIONS as $relation) {
                // The \b word boundary prevents matching partial names like
                // 'MorphOneOrMany'. The [^<,>]+ ensures we only match single-param
                // annotations with no nested angle brackets (e.g. BelongsTo<User>),
                // leaving annotations like HasMany<Collection<Post>> untouched to
                // avoid silent corruption of the inner generic.
                $line = (string) \preg_replace(
                    '/\b' . $relation . '<([^<,>]+)>/',
                    $relation . '<$1, self>',
                    $line,
                );
            }

            foreach (self::FOUR_PARAM_RELATIONS as $relation => $pivotClass) {
                // Case 1: v3 single-param — BelongsToMany<T> → BelongsToMany<T, self, Pivot, 'pivot'>
                $line = (string) \preg_replace(
                    '/\b' . $relation . '<([^<,>]+)>/',
                    $relation . '<$1, self, ' . $pivotClass . ", 'pivot'>",
                    $line,
                );

                // Case 2: incomplete two-param — BelongsToMany<T, self> → BelongsToMany<T, self, Pivot, 'pivot'>
                // Psalm does not support template-param defaults, so two params is not enough.
                // Matching ', self>' explicitly avoids touching annotations with a custom declaring model.
                $line = (string) \preg_replace(
                    '/\b' . $relation . '<([^<,>]+), self>/',
                    $relation . '<$1, self, ' . $pivotClass . ", 'pivot'>",
                    $line,
                );
            }
        }

        return \implode("\n", $lines);
    }

    /**
     * Attempt to auto-fix HasManyThrough / HasOneThrough annotations by extracting
     * the intermediate model from the method body.
     *
     * For a method like:
     *
     *   /** @return HasManyThrough<Post> * /
     *   public function posts(): HasManyThrough
     *   {
     *       return $this->hasManyThrough(Post::class, Country::class);
     *   }
     *
     * this method finds the hasManyThrough() call, reads Country::class (arg 1),
     * resolves it to a fully-qualified name, and rewrites the annotation:
     *
     *   @return HasManyThrough<Post, \App\Models\Country, self>
     *
     * Returns the (possibly unchanged) docblock string.
     */
    private static function upgradeThroughRelations(
        string $docblock,
        FunctionLike $stmt,
        AfterFunctionLikeAnalysisEvent $event,
    ): string {
        // Quick bail: nothing to do if no through relation annotation is present.
        $throughPattern = '/\b(?:' . \implode('|', \array_keys(self::THROUGH_RELATIONS)) . ')<([^<,>]+)>/';
        $hasThrough = false;
        foreach (\explode("\n", $docblock) as $line) {
            if (\preg_match('/@(?:psalm-)?return\b/', $line) && \preg_match($throughPattern, $line)) {
                $hasThrough = true;
                break;
            }
        }
        if (!$hasThrough) {
            return $docblock;
        }

        $lines = \explode("\n", $docblock);
        foreach ($lines as &$line) {
            if (!\preg_match('/@(?:psalm-)?return\b/', $line)) {
                continue;
            }

            foreach (self::THROUGH_RELATIONS as $relation => $methodCallName) {
                if (!\preg_match('/\b' . $relation . '<([^<,>]+)>/', $line)) {
                    continue;
                }

                $throughClass = self::findThroughClass($methodCallName, $stmt, $event);
                if ($throughClass === null) {
                    // Could not resolve — leave unchanged, warning will fire.
                    continue;
                }

                $line = (string) \preg_replace(
                    '/\b' . $relation . '<([^<,>]+)>/',
                    $relation . '<$1, ' . $throughClass . ', self>',
                    $line,
                );
            }
        }

        return \implode("\n", $lines);
    }

    /**
     * Scan the method body for a call to $methodCallName (e.g. 'hasManyThrough')
     * and return the fully-qualified class name of the second argument (the
     * intermediate/through model).
     *
     * Returns null if the call is not found, the second argument is not a
     * ClassName::class expression, or the class name cannot be resolved.
     */
    private static function findThroughClass(
        string $methodCallName,
        FunctionLike $stmt,
        AfterFunctionLikeAnalysisEvent $event,
    ): ?string {
        $stmts = $stmt->getStmts();
        if ($stmts === null) {
            return null;
        }

        /** @var list<MethodCall> $calls */
        $calls = (new NodeFinder())->find(
            $stmts,
            static fn (\PhpParser\Node $node): bool =>
                $node instanceof MethodCall
                && $node->name instanceof Identifier
                && $node->name->name === $methodCallName,
        );

        foreach ($calls as $call) {
            $args = $call->getArgs();
            if (\count($args) < 2) {
                continue;
            }

            $throughArg = $args[1]->value;
            if (!$throughArg instanceof ClassConstFetch) {
                continue;
            }
            // Only handle ClassName::class — skip $variable::class or static::class.
            if (!$throughArg->name instanceof Identifier || $throughArg->name->name !== 'class') {
                continue;
            }

            $class = $throughArg->class;
            if ($class instanceof Name\FullyQualified) {
                return '\\' . $class->toString();
            }
            if ($class instanceof Name) {
                return self::resolveClassName($class, $event);
            }
        }

        return null;
    }

    /**
     * Resolve a PhpParser Name node to a fully-qualified class name string
     * (with a leading backslash) using the file's namespace and use statements.
     *
     * Psalm's NameResolver visitor sets a 'resolvedName' attribute on Name nodes
     * during analysis. We use that when available; otherwise we fall back to
     * manually resolving the name via the source file's alias map.
     */
    private static function resolveClassName(Name $name, AfterFunctionLikeAnalysisEvent $event): string
    {
        // Psalm's NameResolver sets this attribute during file analysis.
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Name\FullyQualified) {
            return '\\' . $resolved->toString();
        }

        // Manual fallback: resolve against the file's use statements.
        // $aliases->uses maps lowercase-alias → FQCN (without leading backslash).
        $aliases = $event->getStatementsSource()->getAliases();
        $parts = $name->parts;
        $lcFirst = \strtolower($parts[0]);

        if (isset($aliases->uses[$lcFirst])) {
            // Replace the leading alias with its fully-qualified import.
            $parts[0] = $aliases->uses[$lcFirst];
            return '\\' . \implode('\\', $parts);
        }

        // Not in use statements — relative to the file's namespace.
        $namespace = $aliases->namespace;
        $relative = \implode('\\', $parts);
        return $namespace !== null ? '\\' . $namespace . '\\' . $relative : '\\' . $relative;
    }

    /**
     * Emit a one-time STDERR warning for through relations that could not be
     * auto-fixed (dynamic call, variable argument, unresolvable class name, etc.).
     *
     * Receives the docblock *after* upgradeThroughRelations() so only genuinely
     * unresolvable cases trigger the warning.
     *
     * We check only @return / @psalm-return lines to avoid false positives from
     * @param or @var annotations that mention these types.
     */
    private static function warnIfManualMigrationNeeded(
        string $docblock,
        FunctionLike $stmt,
        AfterFunctionLikeAnalysisEvent $event,
    ): void {
        $pattern = '/\b(?:' . \implode('|', \array_keys(self::THROUGH_RELATIONS)) . ')<([^<,>]+)>/';

        $found = false;
        foreach (\explode("\n", $docblock) as $line) {
            if (\preg_match('/@(?:psalm-)?return\b/', $line) && \preg_match($pattern, $line)) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            return;
        }

        $source = $event->getStatementsSource();
        // Use the fully-qualified class name for readable output (e.g. App\Models\Post::posts)
        // rather than the raw file path.
        $fqcln = $source->getFQCLN();
        $location = $fqcln !== null && $stmt instanceof ClassMethod
            ? $fqcln . '::' . $stmt->name->name
            : $source->getFilePath();

        // De-duplicate: Psalm may re-analyze the same method in multiple context passes.
        if (isset(self::$warned[$location])) {
            return;
        }
        self::$warned[$location] = true;

        \fwrite(
            \STDERR,
            'Manual migration needed: ' . $location . \PHP_EOL
            . '  HasManyThrough / HasOneThrough: could not resolve intermediate model automatically.' . \PHP_EOL
            . '  Expected form: HasManyThrough<TRelated, TIntermediateModel, self>' . \PHP_EOL
            . '  See: https://psalm.github.io/psalm-plugin-laravel/docs/upgrade-v4.html' . \PHP_EOL
            . \PHP_EOL,
        );
    }
}
