<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\ErrorHandler\Collecting;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

/**
 * Whether Psalm's own parse of a compiled shadow will report a ParseError (#1710).
 *
 * Built for the analysis PHP version, the same way `StatementsProvider::parseStatements()` builds
 * Psalm's parser: syntax valid on the newest PHP can still fail on the version being analyzed.
 *
 * @internal
 */
final class ShadowParseCheck
{
    private ?Parser $parser = null;

    /**
     * @psalm-mutation-free
     */
    public function __construct(private readonly int $analysisPhpVersionId) {}

    public function parses(string $contents): bool
    {
        $this->parser ??= (new ParserFactory())->createForVersion(PhpVersion::fromComponents(
            \intdiv($this->analysisPhpVersionId, 10_000),
            \intdiv($this->analysisPhpVersionId % 10_000, 100),
        ));
        $errors = new Collecting();

        try {
            $this->parser->parse($contents, $errors);
        } catch (\Throwable) {
            return false;
        }

        return !$errors->hasErrors();
    }
}
