<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

// A minimal real bootstrap/app.php (branch 1 of ApplicationProvider::doGetApp()) — not the Testbench
// package-mode fallback (branch 3). config('filesystems.disks') only resolves to THIS directory's
// config/filesystems.php under a real bootstrap boot; the Testbench branch boots its own bundled
// skeleton config regardless of the analysed project, so it can never see this fixture's disk list
// (see initUnknownFilesystemDiskHandler()'s boot-mode gate in src/Plugin.php).
return Application::configure(basePath: \dirname(__DIR__))->create();
