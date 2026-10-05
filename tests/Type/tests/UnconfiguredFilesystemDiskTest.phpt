--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

/**
 * The psalm-tester harness boots the Testbench fallback (no bootstrap/app.php for cwd), whose
 * config is not the analysed project's, so the rule must stay disarmed even though the flag is on.
 * Positive emission is covered by UnconfiguredFilesystemDiskEmissionTest.
 */
Storage::disk('s3-old');
?>
--EXPECTF--
