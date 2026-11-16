<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Type;

use Illuminate\Support\Collection;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;

/**
 * Declares a global `class_alias()` before the Laravel plugin boots its app (psalm.xml lists this plugin first),
 * as an app does from a service provider or a Composer `files` entry. The `autoloader` file runs only after that
 * boot, so an alias declared there is no boot-time fact. Consumed by ContainerResolutionFallbackTest.phpt.
 *
 * @internal
 */
final class EagerClassAliasPlugin implements PluginEntryPointInterface
{
    #[\Override]
    public function __invoke(RegistrationInterface $registration, ?\SimpleXMLElement $config = null): void
    {
        \class_alias(Collection::class, 'LegacyCollection');
    }
}
