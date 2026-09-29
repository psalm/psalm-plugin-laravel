<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Pest;

use Psalm\Codebase;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\FunctionParamsProviderEvent;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Rebinds `$this` in Pest's `test()` / `it()` / `beforeEach()` / `afterEach()` closures to the
 * TestCase Pest actually binds them to at runtime.
 *
 * Pest v4 annotates those closures `@param-closure-this TestCall` (src/Functions.php), which PHPStan
 * resolves through `TestCall`'s `@mixin HigherOrderCallables|TestCase|Testable`. Since Psalm 6.19
 * honors the tag too, but not that union mixin, so every `$this->...` in a test turns into
 * `UndefinedThisPropertyFetch` / `UndefinedMethod` on `TestCall` plus a Mixed* cascade. At runtime
 * `$this` is a generated subclass of the TestCase configured via `uses()` / `pest()->extend()`
 * ({@see PestTestCaseResolver}).
 *
 * A handler, not a stub: the class differs per test file. It is a params provider (not a storage
 * swap) so each call site gets its own answer with no shared state to restore; Psalm reads
 * `closure_this_type` from the resolved params list (`ArgumentsAnalyzer::applyParamClosureThisHint()`).
 * Registered at AfterCodebasePopulated only when the scanned function carries Pest's own
 * `TestCall` tag, so a project's unrelated global `test()` is never touched.
 */
final class PestClosureThisHandler implements AfterCodebasePopulatedInterface
{
    private const FUNCTIONS = ['test', 'it', 'beforeeach', 'aftereach'];

    private const PEST_TEST_CALL = 'Pest\PendingCalls\TestCall';

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        // Psalm < 6.19 has no @param-closure-this support: nothing to correct.
        if (!\property_exists(FunctionLikeParameter::class, 'closure_this_type')) {
            return;
        }

        $functions = $event->getCodebase()->functions;
        foreach (self::FUNCTIONS as $functionId) {
            if ($functions->hasStubbedFunction($functionId)
                && self::pestClosureOffset($functions->getStorage(null, $functionId)->params) !== null
            ) {
                $functions->params_provider->registerClosure(
                    $functionId,
                    static fn(FunctionParamsProviderEvent $event): array => self::getFunctionParams($event, $functionId),
                );
            }
        }
    }

    /**
     * Declining returns the unchanged storage params, never null: Psalm assigns a provider's null
     * straight to the call's params and would skip argument checking entirely.
     *
     * @param non-empty-lowercase-string $functionId
     * @return array<int, FunctionLikeParameter>
     */
    public static function getFunctionParams(FunctionParamsProviderEvent $event, string $functionId): array
    {
        $source = $event->getStatementsSource();
        $codebase = $source->getCodebase();
        $params = $codebase->functions->getStorage(null, $functionId)->params;

        $offset = self::pestClosureOffset($params);
        if ($offset === null) {
            return $params;
        }

        $testFile = $source->getFilePath();
        $testCase = PestTestCaseResolver::resolve(
            \rtrim($codebase->config->base_dir, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . 'tests' . \DIRECTORY_SEPARATOR . 'Pest.php',
            $testFile,
            $codebase->file_provider->getContents($testFile),
            static fn(string $class): ?bool => self::isClass($codebase, $class),
        );

        // ClosureAnalyzer binds only a class it has storage for; otherwise `$this` would be unbound.
        if ($testCase === null || !$codebase->classlike_storage_provider->has($testCase)) {
            return $params;
        }

        $param = clone $params[$offset];
        $param->closure_this_type = new Union([new TNamedObject($testCase)]);
        $params[$offset] = $param;

        return $params;
    }

    /** @param array<int, FunctionLikeParameter> $params */
    private static function pestClosureOffset(array $params): ?int
    {
        foreach ($params as $offset => $param) {
            $bound = $param->closure_this_type;
            if ($bound instanceof Union
                && $bound->isSingle()
                && $bound->getSingleAtomic() instanceof TNamedObject
                && $bound->getSingleAtomic()->value === self::PEST_TEST_CALL
            ) {
                return $offset;
            }
        }

        return null;
    }

    private static function isClass(Codebase $codebase, string $class): ?bool
    {
        try {
            $storage = $codebase->classlike_storage_provider->get($class);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return !$storage->is_trait && !$storage->is_interface && !$storage->is_enum;
    }
}
