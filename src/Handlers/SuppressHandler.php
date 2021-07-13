<?php

namespace Psalm\LaravelPlugin\Handlers;

use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Storage\PropertyStorage;
use function array_intersect;
use function in_array;

class SuppressHandler implements AfterClassLikeVisitInterface
{
    /**
     * @var array<string, list<class-string>>
     */
    private const BY_CLASS = [
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

    /**
     * @var array<string, array<class-string, list<string>>>
     */
    private const BY_CLASS_METHOD = [
        'PossiblyUnusedMethod' => [
            'App\Http\Middleware\RedirectIfAuthenticated' => ['handle'],
        ],
    ];

    /**
     * @var array<string, list<class-string>>
     */
    private const BY_PARENT_CLASS = [
        'PropertyNotSetInConstructor' => [
            'Illuminate\Console\Command',
            'Illuminate\Foundation\Http\FormRequest',
            'Illuminate\Notifications\Notification',
        ],
    ];

    /**
     * @var array<string, array<class-string, list<string>>>
     */
    private const BY_PARENT_CLASS_PROPERTY = [
        'NonInvariantDocblockPropertyType' => [
            'Illuminate\Console\Command' => ['description'],
        ],
    ];

    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event)
    {
        $class = $event->getStorage();

        foreach (self::BY_CLASS as $issue => $class_names) {
            if (in_array($class->name, $class_names)) {
                self::suppress($issue, $class);
            }
        }

        foreach (self::BY_CLASS_METHOD as $issue => $method_by_class) {
            foreach ($method_by_class[$class->name] ?? [] as $method_name) {
                self::suppress($issue, $class->methods[$method_name] ?? null);
            }
        }

        foreach (self::BY_PARENT_CLASS as $issue => $parent_classes) {
            if (!array_intersect($class->parent_classes, $parent_classes)) {
                continue;
            }

            self::suppress($issue, $class);
        }

        foreach (self::BY_PARENT_CLASS_PROPERTY as $issue => $methods_by_parent_class) {
            foreach ($methods_by_parent_class as $parent_class => $property_names) {
                if (!in_array($parent_class, $class->parent_classes)) {
                    continue;
                }

                foreach ($property_names as $property_name) {
                    self::suppress($issue, $class->properties[$property_name] ?? null);
                }
            }
        }
    }

    /**
     * @param string $issue
     * @param ClassLikeStorage|PropertyStorage|MethodStorage|null $storage
     */
    private static function suppress(string $issue, $storage): void
    {
        if ($storage && !in_array($issue, $storage->suppressed_issues)) {
            $storage->suppressed_issues[] = $issue;
        }
    }
}
