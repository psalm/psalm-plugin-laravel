<?php

declare(strict_types=1);

// The checker is standalone (no composer autoload), so the CI script and its unit test load it by hand.
require_once __DIR__ . '/TodoByMarker.php';
require_once __DIR__ . '/IssueState.php';
require_once __DIR__ . '/TodoByReport.php';
require_once __DIR__ . '/TodoByChecker.php';
require_once __DIR__ . '/GitHubIssueStateResolver.php';
