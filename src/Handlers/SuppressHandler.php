<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers;

use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Storage\PropertyStorage;

use function array_intersect;
use function in_array;
use function strtolower;

final class SuppressHandler implements AfterClassLikeVisitInterface
{
    private const CLASS_LEVEL_BY_PARENT_CLASS = [
        'PropertyNotSetInConstructor' => [
            'Illuminate\Console\Command',
            'Illuminate\Foundation\Http\FormRequest',
            'Illuminate\Mail\Mailable',
            'Illuminate\Notifications\Notification',
        ],
        'UnusedClass' => [ // usually classes with auto-discovery
            'Illuminate\Console\Command',
            'Illuminate\Support\ServiceProvider',
        ],
    ];

    private const CLASS_LEVEL_BY_USED_TRAITS = [
        'PropertyNotSetInConstructor' => [
            'Illuminate\Queue\InteractsWithQueue',
        ]
    ];

    /** Less flexible way, used when we can't rely on parent classes */
    private const CLASS_LEVEL_BY_FQCN = [
        'UnusedClass' => [
            'App\Console\Kernel',
            'App\Exceptions\Handler',
            'App\Http\Controllers\Controller',
            'App\Http\Kernel',
            'App\Http\Middleware\Authenticate',
            'App\Http\Middleware\TrustHosts',
            'App\Providers\AppServiceProvider',
            'App\Providers\AuthServiceProvider',
            'App\Providers\BroadcastServiceProvider',
            'App\Providers\EventServiceProvider',
        ],
    ];

    /** Not preferable way as applications may use custom namespaces and structure */
    private const METHOD_LEVEL_BY_FQCN = [
        'PossiblyUnusedMethod' => [
            'App\Http\Middleware\RedirectIfAuthenticated' => ['handle'],
        ],
    ];

    private const PROPERTY_LEVEL_BY_PARENT_CLASS = [
        'NonInvariantDocblockPropertyType' => [
            'Illuminate\Console\Command' => ['description'],
            'Illuminate\View\Component' => ['componentName'],
        ],
        'PropertyNotSetInConstructor' => [
            'Illuminate\Foundation\Testing\TestCase' => ['callbackException', 'app'],
        ],
    ];

    /** @inheritDoc */
    #[\Override]
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        $classStorage = $event->getStorage();

        if (! $classStorage->user_defined) {
            return;
        }
        if ($classStorage->is_interface) {
            return;
        }

        foreach (self::CLASS_LEVEL_BY_FQCN as $issue => $classNames) {
            if (in_array($classStorage->name, $classNames, true)) {
                self::suppress($issue, $classStorage);
            }
        }

        foreach (self::METHOD_LEVEL_BY_FQCN as $issue => $method_by_class) {
            foreach ($method_by_class[$classStorage->name] ?? [] as $method_name) {
                /** @psalm-suppress RedundantFunctionCall */
                $method_storage = $classStorage->methods[strtolower($method_name)] ?? null;
                if ($method_storage instanceof MethodStorage) {
                    self::suppress($issue, $method_storage);
                }
            }
        }

        foreach (self::CLASS_LEVEL_BY_PARENT_CLASS as $issue => $parent_classes) {
            // Check if any of the parent classes match our targets
            if ($classStorage->parent_classes !== [] && array_intersect($classStorage->parent_classes, $parent_classes)) {
                self::suppress($issue, $classStorage);
            } elseif (is_string($classStorage->parent_class) && in_array($classStorage->parent_class, $parent_classes, true)) {
                // If parent_classes array is empty, but we have a direct parent_class, check that
                self::suppress($issue, $classStorage);
            }
        }

        foreach (self::PROPERTY_LEVEL_BY_PARENT_CLASS as $issue => $properties_by_parent_class) {
            foreach ($properties_by_parent_class as $parent_class => $property_names) {
                // Check both parent_classes array and direct parent_class property
                $is_child_of_target_class = false;

                // Check if it inherits from the specific parent class
                if (in_array($parent_class, $classStorage->parent_classes, true)) {
                    $is_child_of_target_class = true;
                } elseif (is_string($classStorage->parent_class) && ($classStorage->parent_class === $parent_class)) {
                    // If parent_classes array is empty, but we have a direct parent_class, check that
                    $is_child_of_target_class = true;
                }

                if (!$is_child_of_target_class) {
                    continue;
                }

                foreach ($property_names as $property_name) {
                    $property_storage = $classStorage->properties[$property_name] ?? null;
                    if ($property_storage instanceof PropertyStorage) {
                        self::suppress($issue, $property_storage);
                    }
                }
            }
        }

        foreach (self::CLASS_LEVEL_BY_USED_TRAITS as $issue => $used_traits) {
            // Skip if traits are empty or if no intersection found
            if ($classStorage->used_traits === [] || !array_intersect($classStorage->used_traits, $used_traits)) {
                continue;
            }

            self::suppress($issue, $classStorage);
        }
    }

    private static function suppress(string $issue, ClassLikeStorage|PropertyStorage|MethodStorage $storage): void
    {
        if (!in_array($issue, $storage->suppressed_issues, true)) {
            $storage->suppressed_issues[] = $issue;
        }
    }

    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event)
    {
        // TODO: Implement afterCodebasePopulated() method.
    }
}
