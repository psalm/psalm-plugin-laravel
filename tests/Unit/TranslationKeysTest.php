<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Config\TranslationKeys;

#[CoversClass(TranslationKeys::class)]
final class TranslationKeysTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function shortKeyProvider(): iterable
    {
        yield 'group.key' => ['auth.failed', true];
        yield 'nested dotted key' => ['validation.attributes.emial', true];
        yield 'slashed group' => ['admin/users.title', true];
        yield 'domain-like, accepted' => ['example.com', true];
        yield 'sentence with whitespace' => ['Online courses - more coming up!', false];
        yield 'sentence with dotted word' => ['See e.g. the docs', false];
        yield 'trailing dot' => ['Done.', false];
        yield 'abbreviation' => ['e.g.', false];
        yield 'leading dot' => ['.env', false];
        yield 'empty segment' => ['a..b', false];
        yield 'no dot' => ['Dashboard', false];
        yield 'whole group' => ['auth', false];
        yield 'empty' => ['', false];
    }

    #[Test]
    #[DataProvider('shortKeyProvider')]
    public function short_mode_reports_only_short_keys(string $key, bool $expected): void
    {
        $this->assertSame($expected, TranslationKeys::Short->shouldReport($key));
    }

    #[Test]
    public function all_mode_reports_every_key(): void
    {
        foreach (self::shortKeyProvider() as [$key]) {
            $this->assertTrue(TranslationKeys::All->shouldReport($key), $key);
        }
    }
}
