<?php

namespace Psalm\LaravelPlugin\Handlers;

use Illuminate\Console\Command;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Notifications\Notification;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Storage\MethodStorage;
use function array_key_exists;
use function in_array;

class SuppressHandler implements AfterClassLikeVisitInterface
{
    private const UNUSED_CLASSES = [
        "App\Console\Kernel",
        "App\Exceptions\Handler",
        "App\Http\Controllers\Controller",
        "App\Http\Kernel",
        "App\Http\Middleware\Authenticate",
        "App\Http\Middleware\TrustHosts",
        "App\Providers\AppServiceProvider",
        "App\Providers\AuthServiceProvider",
        "App\Providers\BroadcastServiceProvider",
        "App\Providers\EventServiceProvider",
    ];

    private const UNUSED_METHODS = [
        "App\Http\Middleware\RedirectIfAuthenticated" => ["handle"],
    ];

    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event)
    {
        $storage = $event->getStorage();

        if (in_array(Command::class, $storage->parent_classes)) {
            if (!in_array('PropertyNotSetInConstructor', $storage->suppressed_issues)) {
                $storage->suppressed_issues[] = 'PropertyNotSetInConstructor';
            }
            if (isset($storage->properties['description'])) {
                $property = $storage->properties['description'];
                if (!in_array('NonInvariantDocblockPropertyType', $property->suppressed_issues)) {
                    $property->suppressed_issues[] = 'NonInvariantDocblockPropertyType';
                }
            }
        }

        // FormRequest: suppress PropertyNotSetInConstructor.
        if (in_array(FormRequest::class, $storage->parent_classes) && !in_array('PropertyNotSetInConstructor', $storage->suppressed_issues)) {
            $storage->suppressed_issues[] = 'PropertyNotSetInConstructor';
        }

        // Notification: suppress PropertyNotSetInConstructor.
        if (in_array(Notification::class, $storage->parent_classes) && !in_array('PropertyNotSetInConstructor', $storage->suppressed_issues)) {
            $storage->suppressed_issues[] = 'PropertyNotSetInConstructor';
        }

        // Suppress UnusedClass on well-known classes.
        if (in_array($storage->name, self::UNUSED_CLASSES)) {
            $storage->suppressed_issues[] = 'UnusedClass';
        }

        // Suppress PossiblyUnusedMethod on well-known methods.
        if (array_key_exists($storage->name, self::UNUSED_METHODS)) {
            foreach (self::UNUSED_METHODS[$storage->name] as $method_name) {
                $method = $storage->methods[$method_name] ?? null;
                if ($method instanceof MethodStorage) {
                    $method->suppressed_issues[] = 'PossiblyUnusedMethod';
                }
            }
        }
    }
}
