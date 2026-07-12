<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Plugin;

/**
 * Source-order guard for the deliberately explicit handler loading convention.
 *
 * This is deliberately a source-order test, not an autoloader test: PluginConfig
 * is already referenced before the initialization helper runs. Keep the check
 * close to the ordering invariant instead.
 */
#[CoversClass(Plugin::class)]
final class PluginInitializationHandlerLoadingTest extends TestCase
{
    #[Test]
    public function initialization_handlers_are_loaded_before_any_optional_init_path(): void
    {
        $source = \file_get_contents(\dirname(__DIR__, 2) . '/src/Plugin.php');

        $this->assertIsString($source);

        $loadCall = \strpos($source, '$this->loadInitializationHandlers();');
        $resetCall = \strpos($source, '$this->resetInvocationState();');
        $try = \strpos($source, '        try {');

        $this->assertIsInt($loadCall);
        $this->assertIsInt($resetCall);
        $this->assertIsInt($try);
        $this->assertLessThan($try, $loadCall);
        $this->assertLessThan($try, $resetCall);
        $this->assertLessThan($resetCall, $loadCall);

        $resetMethod = $this->methodBody($source, 'resetInvocationState');
        foreach ([
            'ApplicationProvider::reset()',
            'FacadeMapProvider::reset()',
            'Handlers\\Translations\\TranslationKeyHandler::reset()',
            'Handlers\\Views\\MissingViewHandler::reset()',
            'Handlers\\Eloquent\\Metadata\\ModelMetadataRegistryBuilder::reset()',
        ] as $reset) {
            $this->assertStringContainsString($reset, $resetMethod);
        }

        $loadMethod = $this->methodBody($source, 'loadInitializationHandlers');
        foreach ([
            '/Handlers/Rules/NoEnvOutsideConfigHandler.php',
            '/Handlers/Translations/TranslationKeyHandler.php',
            '/Handlers/Views/MissingViewHandler.php',
        ] as $handlerFile) {
            $this->assertStringContainsString($handlerFile, $loadMethod);
            $this->assertSame(2, \substr_count($source, $handlerFile), "{$handlerFile} must remain explicitly loaded for both initialization and registration.");
        }

        foreach ([
            'initNoEnvOutsideConfigHandler' => 'Handlers\\Rules\\NoEnvOutsideConfigHandler::init(',
            'initTranslationKeyHandler' => 'Handlers\\Translations\\TranslationKeyHandler::init(',
            'initMissingViewHandler' => 'Handlers\\Views\\MissingViewHandler::init(',
            'initViewFactoryHandler' => 'Handlers\\Views\\MissingViewHandler::initViewFactory(',
        ] as $method => $staticTouch) {
            $methodBody = $this->methodBody($source, $method);

            $this->assertStringContainsString($staticTouch, $methodBody);
            $this->assertStringNotContainsString('require_once', $methodBody);
        }
    }

    private function methodBody(string $source, string $method): string
    {
        $pattern = '/private function ' . \preg_quote($method, '/') . '\\([^)]*\\): void\\n    \\{(.*?)^    \\}/ms';
        $matched = \preg_match($pattern, $source, $matches);

        $this->assertSame(1, $matched, "Could not find {$method}() in Plugin.php");

        return $matches[1];
    }
}
