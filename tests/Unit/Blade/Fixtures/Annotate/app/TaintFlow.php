<?php

declare(strict_types=1);

namespace AnnotateFixture;

/** A request-to-echo flow, so an ordinary run of this fixture reports TaintedHtml. */
final class TaintFlow
{
    public function echoQuery(): void
    {
        echo (string) ($_GET['q'] ?? '');
    }
}
