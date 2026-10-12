<?php

declare(strict_types=1);

namespace ViewContractFixture;

// Every view declares `$user`, and no call site passes it. See app/Runtime/ShareViewData.php.
final class RuntimeComposerCallSites
{
    public function render(): void
    {
        view('runtime-facade', []);
        view('runtime-helper', []);
        view('runtime-injected', []);
        view('runtime-property', []);
        view('runtime-assigned', []);
        view('runtime-container', []);
        view('runtime-map', []);
        view('runtime-wild.page', []);
        view('runtime-closure-provider', []);
        view('runtime-userland', []);
        view('runtime-unmatched', []);
        // `title` and `tags` come from the provider's class composer.
        view('composer-provider', []);
        view('composer-mixed', []);
    }
}
