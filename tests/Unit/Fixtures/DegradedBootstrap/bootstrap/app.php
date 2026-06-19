<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

// A real bootstrap/app.php so the plugin takes the bootstrap-file branch (not the
// Testbench fallback). The returned Application is not yet bootstrapped; the plugin's
// $consoleApp->bootstrap() drives LoadConfiguration over config/*.php, where bad.php
// fatals — exercising the swallowed-bootstrap path the test guards (#1096).
return Application::configure(basePath: dirname(__DIR__))->create();
