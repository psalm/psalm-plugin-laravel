<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Where {@see \token_get_all()} found genuine PHP-mode constructs in a compiled shadow. A regex
 * match alone cannot tell compiler output from author text that merely looks like it (a comment or
 * string holding the same bytes); a byte offset PHP's own lexer reports as a token start can.
 *
 * @internal
 *
 * @psalm-immutable
 */
final class PhpTokenOffsets
{
    /**
     * @return array{0: array<int, true>, 1: array<int, string>} start offsets of every `<?php`/`<?=`
     *     transition into PHP mode, and start offset => text of every doc comment
     *
     * @psalm-pure
     */
    public static function scan(string $compiled): array
    {
        $openTags = [];
        $docComments = [];
        $offset = 0;

        foreach (\token_get_all($compiled) as $token) {
            if (\is_array($token)) {
                if ($token[0] === \T_OPEN_TAG || $token[0] === \T_OPEN_TAG_WITH_ECHO) {
                    $openTags[$offset] = true;
                } elseif ($token[0] === \T_DOC_COMMENT) {
                    $docComments[$offset] = $token[1];
                }
            }

            $offset += \strlen(\is_array($token) ? $token[1] : $token);
        }

        return [$openTags, $docComments];
    }
}
