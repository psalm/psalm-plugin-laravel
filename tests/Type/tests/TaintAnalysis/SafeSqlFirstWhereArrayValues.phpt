--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

class FirstWhereDriver extends \Illuminate\Database\Eloquent\Model {}

/**
 * firstWhere(['col' => $value]) delegates to where()->first(), so the keyed-array values
 * are PDO-bound too. Regression guard for #733 (closed as a duplicate of #734).
 *
 * @psalm-suppress MixedAssignment
 */
function safeFirstWhereArray(\Illuminate\Http\Request $request): void {
    $driverId = $request->input('driver_id');

    FirstWhereDriver::firstWhere(['driver_id' => $driverId, 'active' => 1]);
}
?>
--EXPECTF--
