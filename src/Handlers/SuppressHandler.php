<?php

namespace Psalm\LaravelPlugin\Handlers;

use Illuminate\Console\Command;
use Illuminate\Foundation\Http\FormRequest;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use function in_array;

class SuppressHandler implements AfterClassLikeVisitInterface
{
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event)
    {
        $storage = $event->getStorage();

        // Commands: suppress PropertyNotSetInConstructor.
        if (in_array(Command::class, $storage->parent_classes) && !in_array('PropertyNotSetInConstructor', $storage->suppressed_issues)) {
            $storage->suppressed_issues[] = 'PropertyNotSetInConstructor';
        }

        // FormRequest: suppress PropertyNotSetInConstructor.
        if (in_array(FormRequest::class, $storage->parent_classes) && !in_array('PropertyNotSetInConstructor', $storage->suppressed_issues)) {
            $storage->suppressed_issues[] = 'PropertyNotSetInConstructor';
        }
    }
}
