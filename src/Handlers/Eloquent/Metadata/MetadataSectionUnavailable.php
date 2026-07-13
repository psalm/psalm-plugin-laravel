<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Metadata;

/**
 * Internal control flow for a section whose required input is legitimately absent.
 * Unlike a failed section, this produces no user-visible warning.
 *
 * @internal
 */
final class MetadataSectionUnavailable extends \RuntimeException {}
