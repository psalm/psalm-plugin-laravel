<?php

declare(strict_types=1);

// The fixture ships its own composer.json so Laravel's Application::getNamespace() (invoked while
// compiling an `<x-...>` component tag) can resolve without throwing. Nothing in the fixture is
// loaded through Composer's autoloader — app/Providers/LivewireStubProvider.php is required
// directly by bootstrap/app.php — so this file only needs to exist, not do anything.
