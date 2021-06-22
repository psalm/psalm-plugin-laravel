<?php

namespace Psalm\LaravelPlugin\Handlers\Application;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\RateLimiter;
use Psalm\LaravelPlugin\Providers\ApplicationInterfaceProvider;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use function in_array;
use function array_merge;
use function array_values;
use function strtolower;

/**
 * @see https://github.com/psalm/psalm-plugin-symfony/issues/25
 * psalm needs to know about any classes that could be returned before analysis begins. This is a naive first approach
 */
final class ContainerHandler implements AfterClassLikeVisitInterface
{
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event)
    {
        if (!in_array($event->getStorage()->name, ApplicationInterfaceProvider::getApplicationInterfaceClassLikes())) {
            return;
        }

        $appClassName = ApplicationProvider::getAppFullyQualifiedClassName();

        $facades =  ApplicationProvider::getApp()->make('config')->get('app.aliases', []);
        // I'm not sure why this isn't included by default, but this is a hack that fixes the bug
        $facades['rl'] = RateLimiter::class;

        $classesThatCouldBeReturnedThatArentReferencedAlready = array_merge(
            [$appClassName],
            array_values(AliasLoader::getInstance($facades)->getAliases()),
        );

        foreach ($classesThatCouldBeReturnedThatArentReferencedAlready as $className) {
            $filePath = $event->getStatementsSource()->getFilePath();
            $fileStorage = $event->getCodebase()->file_storage_provider->get($filePath);
            $fileStorage->referenced_classlikes[strtolower($className)] = $className;
            $event->getCodebase()->queueClassLikeForScanning($className);
        }
    }
}
