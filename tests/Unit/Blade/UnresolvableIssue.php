<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Psalm\CodeLocation;
use Psalm\Issue\CodeIssue;

/**
 * Every one of Psalm's own issue classes exposes each constructor parameter as a readable property,
 * which is what makes the reflective rebuild universal. This one deliberately does not, so the
 * relocator's decline path stays covered if that ever stops being true upstream.
 */
final class UnresolvableIssue extends CodeIssue
{
    public function __construct(string $message, CodeLocation $code_location, string $unreadable)
    {
        parent::__construct($message . \strlen($unreadable), $code_location);
    }
}
