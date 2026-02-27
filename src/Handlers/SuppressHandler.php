<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers;

use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Storage\PropertyStorage;

use function array_intersect;
use function in_array;
use function strtolower;

final class SuppressHandler implements AfterClassLikeVisitInterface, AfterCodebasePopulatedInterface
{
    /** @var array<string, list<string>> */
    private const CLASS_LEVEL_BY_PARENT_CLASS = [
        'PropertyNotSetInConstructor' => [
            'Illuminate\Console\Command',
            'Illuminate\Foundation\Http\FormRequest',
            'Illuminate\Mail\Mailable',
            'Illuminate\Notifications\Notification',
            'Illuminate\View\Component',
        ],
        'UnusedClass' => [
            'Illuminate\Console\Command',
            'Illuminate\Support\ServiceProvider',
        ],
    ];

    /** @var array<string, list<string>> */
    private const CLASS_LEVEL_BY_USED_TRAITS = [
        'PropertyNotSetInConstructor' => [
            'Illuminate\Queue\InteractsWithQueue',
        ],
    ];

    /**
     * Suppress class-level issues by FQCN.
     * Less flexible — use parent class or trait based checks when possible.
     *
     * @var array<string, list<string>>
     */
    private const CLASS_LEVEL_BY_FQCN = [
        'UnusedClass' => [
            'App\Console\Kernel',
            'App\Exceptions\Handler',
            'App\Http\Controllers\Controller',
            'App\Http\Kernel',
            'App\Http\Middleware\Authenticate',
            'App\Http\Middleware\TrustHosts',
        ],
    ];

    /**
     * Suppress method-level issues by FQCN.
     * Not preferable — applications may use custom namespaces.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const METHOD_LEVEL_BY_FQCN = [
        'PossiblyUnusedMethod' => [
            'App\Http\Middleware\RedirectIfAuthenticated' => ['handle'],
        ],
    ];

    /** @var array<string, array<string, list<string>>> */
    private const PROPERTY_LEVEL_BY_PARENT_CLASS = [
        'NonInvariantDocblockPropertyType' => [
            'Illuminate\Console\Command' => ['description'],
            'Illuminate\Database\Eloquent\Model' => [
                'fillable', 'guarded', 'hidden', 'casts', 'appends', 'touches',
                'with', 'withCount', 'connection', 'table', 'primaryKey', 'keyType',
                'perPage', 'incrementing', 'timestamps', 'dateFormat',
                'attributes', 'dispatchesEvents', 'observables',
            ],
            'Illuminate\View\Component' => ['componentName'],
        ],
        'PropertyNotSetInConstructor' => [
            'Illuminate\Foundation\Testing\TestCase' => ['callbackException', 'app'],
        ],
    ];

    /** @var array<string, array<string, list<string>>> */
    private const METHOD_LEVEL_BY_PARENT_CLASS = [
        'PossiblyUnusedMethod' => [
            'Illuminate\Mail\Mailable' => ['__construct', 'build', 'envelope', 'content', 'attachments'],
            'Illuminate\Notifications\Notification' => ['__construct', 'via', 'toMail', 'toArray'],
        ],
    ];

    /** @var array<string, array<string, list<string>>> */
    private const METHOD_LEVEL_BY_USED_TRAITS = [
        'PossiblyUnusedMethod' => [
            'Illuminate\Foundation\Events\Dispatchable' => ['broadcastOn'],
            'Illuminate\Foundation\Bus\Dispatchable' => ['handle'],
        ],
    ];

    /** @inheritDoc */
    #[\Override]
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        $classStorage = $event->getStorage();

        if (!$classStorage->user_defined) {
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
                /** @psalm-suppress RedundantFunctionCall method names in constants may contain uppercase */
                $method_storage = $classStorage->methods[strtolower($method_name)] ?? null;
                if ($method_storage instanceof MethodStorage) {
                    self::suppress($issue, $method_storage);
                }
            }
        }
    }

    /**
     * Hierarchy-based suppressions run after codebase population, when parent_classes is fully resolved.
     * This fixes the issue where AfterClassLikeVisit only has one level of parent hierarchy.
     */
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        foreach ($event->getCodebase()->classlike_storage_provider->getAll() as $classStorage) {
            if (!$classStorage->user_defined) {
                continue;
            }
            if ($classStorage->is_interface) {
                continue;
            }

            self::suppressByParentClass($classStorage);
            self::suppressByUsedTraits($classStorage);
        }
    }

    private static function suppressByParentClass(ClassLikeStorage $classStorage): void
    {
        $parents = $classStorage->parent_classes;

        if ($parents === []) {
            return;
        }

        foreach (self::CLASS_LEVEL_BY_PARENT_CLASS as $issue => $parent_classes) {
            if (array_intersect($parents, $parent_classes)) {
                self::suppress($issue, $classStorage);
            }
        }

        foreach (self::PROPERTY_LEVEL_BY_PARENT_CLASS as $issue => $properties_by_parent_class) {
            foreach ($properties_by_parent_class as $parent_class => $property_names) {
                if (!in_array($parent_class, $parents, true)) {
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

        foreach (self::METHOD_LEVEL_BY_PARENT_CLASS as $issue => $methods_by_parent_class) {
            foreach ($methods_by_parent_class as $parent_class => $method_names) {
                if (!in_array($parent_class, $parents, true)) {
                    continue;
                }

                foreach ($method_names as $method_name) {
                    $method_storage = $classStorage->methods[strtolower($method_name)] ?? null;
                    if ($method_storage instanceof MethodStorage) {
                        self::suppress($issue, $method_storage);
                    }
                }
            }
        }
    }

    private static function suppressByUsedTraits(ClassLikeStorage $classStorage): void
    {
        if ($classStorage->used_traits === []) {
            return;
        }

        foreach (self::CLASS_LEVEL_BY_USED_TRAITS as $issue => $traits) {
            foreach ($traits as $trait) {
                if (isset($classStorage->used_traits[strtolower($trait)])) {
                    self::suppress($issue, $classStorage);
                    break;
                }
            }
        }

        foreach (self::METHOD_LEVEL_BY_USED_TRAITS as $issue => $methods_by_trait) {
            foreach ($methods_by_trait as $trait => $method_names) {
                if (!isset($classStorage->used_traits[strtolower($trait)])) {
                    continue;
                }

                foreach ($method_names as $method_name) {
                    $method_storage = $classStorage->methods[strtolower($method_name)] ?? null;
                    if ($method_storage instanceof MethodStorage) {
                        self::suppress($issue, $method_storage);
                    }
                }
            }
        }
    }

    private static function suppress(string $issue, ClassLikeStorage|PropertyStorage|MethodStorage $storage): void
    {
        if (!in_array($issue, $storage->suppressed_issues, true)) {
            $storage->suppressed_issues[] = $issue;
        }
    }
}
