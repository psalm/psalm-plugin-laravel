<?php

declare(strict_types=1);

namespace RouteHelperFixture;

/**
 * Deliberately OUTSIDE `<projectFiles>` (psalm.xml lists `app` only): Psalm must never scan this
 * file on its own, so a passing test proves the plugin's shadow-literal queueing found it, not
 * that projectFiles happened to cover it too.
 */
final class RouteGenerator
{
    public static function generate(): string
    {
        return '<script></script>';
    }
}
