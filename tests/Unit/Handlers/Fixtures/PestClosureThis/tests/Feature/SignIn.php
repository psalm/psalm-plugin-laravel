<?php

declare(strict_types=1);

// Mapped by tests/Pest.php `pest()->extend(FeatureTestCase::class)->in('Feature')`.
// Fixture files skip the Test.php suffix so the plugin's own PHPUnit run does not load them.
test('reads the directory TestCase', function (): void {
    /** @psalm-check-type-exact $this = PestClosureThisFixture\FeatureTestCase */
    $_value = $this->featureOnly;
    /** @psalm-check-type-exact $_value = string */
    $_id = $this->signIn();
    /** @psalm-check-type-exact $_id = int */
});
