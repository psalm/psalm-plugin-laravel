<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Pest;

use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;

/**
 * Lifts Pest's class-level `@internal` from the classes that ARE its public test-writing DSL, so
 * `expect($x)->toBeTrue()`, `uses(...)->in(...)` and `test(...)->group(...)` stop reporting
 * `InternalMethod`. Pest marks them `@internal` to keep them out of its BC promise, not to forbid
 * the fluent calls its documentation teaches.
 *
 * Psalm prepends a class's `internal` list onto each of its methods and properties at population,
 * once per population pass (`Populator::populateClassLikeStorage()`), and `MethodCallProhibitionAnalyzer`
 * checks only the member's copy, so the class and every member are cleared. None of the listed
 * classes marks a member `@internal` itself (pest v4.7.0), so dropping the class's entries is exact.
 */
final class PestInternalDslHandler implements AfterCodebasePopulatedInterface
{
    /**
     * Return types of the functions in Pest's src/Functions.php and of the fluent calls on them.
     * `Pest\Expectation` itself is not internal; its `toX()` assertions live on the `@mixin`.
     */
    private const DSL_CLASSES = [
        'Pest\Mixins\Expectation',
        'Pest\Expectations\OppositeExpectation',
        'Pest\Expectations\EachExpectation',
        'Pest\Expectations\HigherOrderExpectation',
        'Pest\PendingCalls\TestCall',
        'Pest\PendingCalls\BeforeEachCall',
        'Pest\PendingCalls\AfterEachCall',
        'Pest\PendingCalls\DescribeCall',
        'Pest\PendingCalls\UsesCall',
        'Pest\Configuration',
        'Pest\Support\HigherOrderTapProxy',
    ];

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $storageProvider = $event->getCodebase()->classlike_storage_provider;

        foreach (self::DSL_CLASSES as $class) {
            if (!$storageProvider->has($class)) {
                continue;
            }

            $storage = $storageProvider->get($class);
            $classInternal = $storage->internal;
            if ($classInternal === []) {
                continue;
            }

            $storage->internal = [];

            // Methods and properties are keyed by name and may share one, so no merged loop.
            foreach ($storage->methods as $method) {
                $method->internal = \array_values(\array_diff($method->internal, $classInternal));
            }

            foreach ($storage->properties as $property) {
                $property->internal = \array_values(\array_diff($property->internal, $classInternal));
            }
        }
    }
}
