<?php

declare(strict_types=1);

// Simulates a config/*.php that fatals while LoadConfiguration evaluates it, aborting
// bootstrap() (the #1096 mechanism). intdiv(1, 0) throws DivisionByZeroError on every
// supported PHP version regardless of typing mode, so it is a deterministic stand-in for
// the issue's real parse_url(env('UNSET')) case (which throws a TypeError only under
// declare(strict_types=1); in coercive mode PHP 8.1+ only deprecates it instead of throwing).
return [
    'value' => intdiv(1, 0),
];
