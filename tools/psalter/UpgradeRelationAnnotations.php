<?php

declare(strict_types=1);

use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassMethod;
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
 *   @return BelongsTo<User>            → @return BelongsTo<User, self>
 *   @return HasMany<Post>              → @return HasMany<Post, self>
 *   @return HasOne<Profile>            → @return HasOne<Profile, self>
 *   @return BelongsToMany<Tag>         → @return BelongsToMany<Tag, self, Pivot, 'pivot'>
 *   @return BelongsToMany<Tag, self>   → @return BelongsToMany<Tag, self, Pivot, 'pivot'>
 *   @return MorphOne<Image>            → @return MorphOne<Image, self>
 *   @return MorphMany<Tag>             → @return MorphMany<Tag, self>
 *   @return MorphTo<Model>             → @return MorphTo<Model, self>
 *   @return MorphToMany<Tag>           → @return MorphToMany<Tag, self, Pivot, 'pivot'>
 *   @return MorphToMany<Tag, self>     → @return MorphToMany<Tag, self, Pivot, 'pivot'>
 *
 * What requires manual migration (a warning is emitted for these):
 *
 *   @return HasManyThrough<Post> → HasManyThrough<Post, TIntermediateModel, self>
 *   @return HasOneThrough<Head>  → HasOneThrough<Head, TIntermediateModel, self>
 *
 * Both @return and @psalm-return annotations are handled.
 * Fully-qualified names (e.g. \Illuminate\...\BelongsTo<User>) are also handled.
 * Annotations that already have a comma in the type param slot are left unchanged.
 */
final class UpgradeRelationAnnotations implements AfterFunctionLikeAnalysisInterface
{
    /**
     * Relations where TDeclaringModel becomes the second type parameter.
     * BelongsToMany and MorphToMany are included here so that 1-param annotations
     * gain `self` first; a second PIVOT_RELATIONS pass then appends the two
     * remaining pivot params.
     *
     * @var list<string>
     */
    private const AUTO_RELATIONS = [
        'BelongsTo',
        'BelongsToMany',
        'HasMany',
        'HasOne',
        'MorphMany',
        'MorphOne',
        'MorphTo',
        'MorphToMany',
    ];

    /**
     * Relations that gained TPivotModel and TAccessor template parameters in v4.7.
     * After the AUTO_RELATIONS pass ensures TDeclaringModel is present, this pass
     * upgrades 2-param annotations to the full 4-param signature, defaulting to
     * the base Pivot class and the standard 'pivot' accessor.
     *
     * Annotations already at 4 params are left untouched because the second
     * capture group ([^<,>]+) stops at the first comma and the closing '>' then
     * fails to match.
     *
     * @var list<string>
     */
    private const PIVOT_RELATIONS = [
        'BelongsToMany',
        'MorphToMany',
    ];

    /**
     * Relations that cannot be automatically migrated because TIntermediateModel
     * must be inserted as the second type parameter, and we cannot infer it from
     * the docblock alone.
     *
     * @var list<string>
     */
    private const MANUAL_RELATIONS = [
        'HasManyThrough',
        'HasOneThrough',
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
            self::warnIfManualMigrationNeeded($originalText, $stmt, $event);

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
     * Apply all v3 → v4 transformations to a raw docblock string.
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

            // Second pass: upgrade BelongsToMany/MorphToMany from 2 params to 4.
            // After the AUTO pass above, any previously 1-param annotation is now
            // at 2 params, so both 1-param (now 2) and already-2-param annotations
            // are caught here in the same upgradeDocblock() call.
            // Annotations already at 4 params are skipped: [^<,>]+ stops at the
            // second comma, so > cannot match and preg_replace leaves the line unchanged.
            foreach (self::PIVOT_RELATIONS as $relation) {
                $line = (string) \preg_replace(
                    '/\b' . $relation . '<([^<,>]+),\s*([^<,>]+)>/',
                    $relation . '<$1, $2, \Illuminate\Database\Eloquent\Relations\Pivot, \'pivot\'>',
                    $line,
                );
            }
        }

        return \implode("\n", $lines);
    }

    /**
     * Emit a one-time STDERR warning for relations that require manual migration.
     * These have an additional TIntermediateModel parameter that we cannot infer.
     *
     * We check only @return / @psalm-return lines to avoid false positives from
     * @param or @var annotations that mention these types.
     */
    private static function warnIfManualMigrationNeeded(
        string $docblock,
        FunctionLike $stmt,
        AfterFunctionLikeAnalysisEvent $event,
    ): void {
        $pattern = '/\b(?:' . \implode('|', self::MANUAL_RELATIONS) . ')<([^<,>]+)>/';

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
            . '  HasManyThrough / HasOneThrough require specifying the intermediate model:' . \PHP_EOL
            . '  HasManyThrough<TRelated, TIntermediateModel, self>' . \PHP_EOL
            . '  See: https://psalm.github.io/psalm-plugin-laravel/docs/upgrade-v4.html' . \PHP_EOL
            . \PHP_EOL,
        );
    }
}
