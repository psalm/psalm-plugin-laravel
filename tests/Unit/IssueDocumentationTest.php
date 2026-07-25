<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class IssueDocumentationTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function issueClassNames(): array
    {
        $root = \dirname(__DIR__, 2);
        $files = \glob($root . '/src/Issues/*.php');
        self::assertNotFalse($files);

        $names = [];
        foreach ($files as $file) {
            $names[] = \basename($file, '.php');
        }

        self::assertNotEmpty($names, 'src/Issues/*.php discovery found nothing; the source shape changed');

        \sort($names);

        return $names;
    }

    #[Test]
    public function every_issue_class_has_a_matching_doc_page_and_vice_versa(): void
    {
        $root = \dirname(__DIR__, 2);

        $docFiles = \glob($root . '/docs/issues/*.md');
        self::assertNotFalse($docFiles);

        $docNames = [];
        foreach ($docFiles as $file) {
            $name = \basename($file, '.md');
            if ($name === 'index') {
                continue;
            }
            $docNames[] = $name;
        }

        self::assertEqualsCanonicalizing(
            self::issueClassNames(),
            $docNames,
            'src/Issues/*.php and docs/issues/*.md must name the same set of issues (an orphan page has no source class, a missing page has no docs)',
        );
    }

    #[Test]
    public function every_issue_class_documentation_url_points_to_its_own_page(): void
    {
        foreach (self::issueClassNames() as $name) {
            $fqcn = "Psalm\\LaravelPlugin\\Issues\\{$name}";
            $expected = "https://psalm.github.io/psalm-plugin-laravel/issues/{$name}/";

            self::assertSame(
                $expected,
                \constant("{$fqcn}::DOCUMENTATION_URL"),
                "{$fqcn}::DOCUMENTATION_URL does not match its own issue page",
            );
        }
    }

    #[Test]
    public function index_lists_every_issue(): void
    {
        $root = \dirname(__DIR__, 2);
        $index = \file_get_contents($root . '/docs/issues/index.md');
        self::assertIsString($index);

        foreach (self::issueClassNames() as $name) {
            self::assertStringContainsString(
                "[{$name}]({$name}.md)",
                $index,
                "docs/issues/index.md has no entry for {$name}",
            );
        }
    }

    #[Test]
    public function every_config_element_is_documented(): void
    {
        $root = \dirname(__DIR__, 2);
        $source = \file_get_contents($root . '/src/Config/PluginConfig.php');
        self::assertIsString($source);

        \preg_match_all(
            "/xml(?:Bool|OptionalBool|String)Attr\(\\\$config\?->\w+,\s*'(\w+)'/",
            $source,
            $attrMatches,
        );
        $attrElements = $attrMatches[1];
        self::assertNotEmpty($attrElements, 'the PluginConfig.php source shape changed; xml*Attr regex captured nothing');

        \preg_match_all(
            "/xmlNameList\(\\\$config,\s*'(\w+)'/",
            $source,
            $listMatches,
        );
        $listElements = $listMatches[1];
        self::assertNotEmpty($listElements, 'the PluginConfig.php source shape changed; xmlNameList regex captured nothing');

        $elements = [...$attrElements, ...$listElements];
        // modelProperties is the element for xmlStringAttr($config?->modelProperties, 'columnFallback', ...):
        // the regex captures the ATTRIBUTE name ('columnFallback'), not the element name, so no pattern sees it.
        $elements[] = 'modelProperties';

        $configDocs = \file_get_contents($root . '/docs/config.md');
        self::assertIsString($configDocs);

        foreach (\array_unique($elements) as $element) {
            self::assertStringContainsString(
                $element,
                $configDocs,
                "docs/config.md does not mention the '{$element}' config element",
            );
        }
    }
}
