<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Auth;

use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Applies Sanctum's documented default to direct, unparameterized HasApiTokens uses.
 *
 * @internal
 */
final class SanctumTokenTemplateHandler implements AfterClassLikeVisitInterface
{
    private const HAS_API_TOKENS_LC = 'laravel\\sanctum\\hasapitokens';
    private const PERSONAL_ACCESS_TOKEN = 'Laravel\\Sanctum\\PersonalAccessToken';

    #[\Override]
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        $storage = $event->getStorage();
        $has_api_tokens = $storage->used_traits[self::HAS_API_TOKENS_LC] ?? null;

        if ($has_api_tokens === null
            || isset($storage->template_type_uses_count[self::HAS_API_TOKENS_LC])
            || isset($storage->template_extended_offsets[$has_api_tokens])
        ) {
            return;
        }

        $token_type = new Union([new TNamedObject(self::PERSONAL_ACCESS_TOKEN)]);
        $storage->template_type_uses_count[self::HAS_API_TOKENS_LC] = 1;
        $storage->template_extended_offsets[$has_api_tokens] = [$token_type];

        $file_storage = $event->getCodebase()->file_storage_provider->get(
            $event->getStatementsSource()->getFilePath(),
        );
        /** @psalm-suppress UnusedMethodCall */
        $token_type->queueClassLikesForScanning($event->getCodebase(), $file_storage);
    }
}
