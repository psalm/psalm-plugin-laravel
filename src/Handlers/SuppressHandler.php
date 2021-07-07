<?php

namespace Psalm\LaravelPlugin\Handlers;

use Illuminate\Console\Command;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Notifications\Notification;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use function in_array;

class SuppressHandler implements AfterClassLikeVisitInterface
{
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
    }
}
