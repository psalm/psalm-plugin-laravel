<?php

declare(strict_types=1);

namespace BladeIssueRemapFixture;

// A receiver with one real instance method and one real static method, so a template can call
// either on a union with `array` (instance) or on a class-string-less mixed subject (static).
final class Catalog
{
    /** @return array<string, string> */
    public function items(): array
    {
        return [];
    }

    /** @return array<string, string> */
    public static function make(): array
    {
        return [];
    }
}
