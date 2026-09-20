<?php

declare(strict_types=1);

namespace BladeIssueRemapFixture;

// A three-parameter receiver: both the precompiler-generated call (5 arguments) and the
// author-written call in the template (4 arguments) overshoot it, so both raise TooManyArguments;
// only the author-written one is expected to survive the shadow-emission suppression (#1498).
final class LivewireMountTarget {}
