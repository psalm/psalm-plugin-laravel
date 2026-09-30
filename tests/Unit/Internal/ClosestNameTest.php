<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Internal\ClosestName;

#[CoversClass(ClosestName::class)]
final class ClosestNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>, ?string}>
     */
    public static function cases(): iterable
    {
        yield 'one deletion' => ['publc', ['local', 'public', 's3'], 'public'];
        yield 'transposition within threshold' => ['pubilc', ['local', 'public'], 'public'];
        yield 'nearest of several close names wins' => ['s4', ['s3', 'sftp'], 's3'];
        yield 'short name, one edit allowed' => ['s', ['s3'], 's3'];
        yield 'too far to read as a typo' => ['s3-old', ['local', 'public', 's3'], null];
        yield 'short name never jumps to an unrelated one' => ['ftp', ['local', 's3'], null];
        yield 'no candidates' => ['public', [], null];
        yield 'tie keeps the first candidate' => ['ab', ['aa', 'bb'], 'aa'];
    }

    /**
     * @param list<string> $candidates
     */
    #[Test]
    #[DataProvider('cases')]
    public function it_suggests_only_a_close_candidate(string $name, array $candidates, ?string $expected): void
    {
        $this->assertSame($expected, ClosestName::find($name, $candidates));
    }
}
