<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Psalm\LaravelPlugin\Util\ModelPropertyResolver;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Union;

/**
 * Narrows the return type of Builder::pluck() using model @property annotations.
 *
 * Without this handler, Builder::pluck('email') returns Collection<array-key, mixed>.
 * With it, if the model declares `@property string $email`, the return becomes
 * Collection<int, string>.
 *
 * Also handles User::pluck('column') which is proxied through ModelMethodHandler
 * to Builder<User>::pluck('column').
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/486
 * @internal
 */
final class PluckHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Builder::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'pluck') {
            return null;
        }

        // Builder<TModel> — TModel is template param at index 0
        return ModelPropertyResolver::resolvePluckReturnType($event, modelTemplateIndex: 0);
    }
}
