<?php

declare(strict_types=1);

namespace BladeIssueRemapFixture;

// A concrete receiver for the templates: a misspelled property on a final class with no __get is
// the smallest expression that produces one unambiguous issue type. Deliberately memberless, so the
// only issues this fixture can report are the ones the templates cause.
final class Greeter {}
