--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

enum Status: string {
    case Active = 'active';
    case Inactive = 'inactive';
}

/** enums() returns BackedEnum[] via tryFrom() — strict whitelist, not a taint source. */
function useEnumsInput(\Illuminate\Http\Request $request): void {
    $statuses = $request->enums('statuses', Status::class);
    echo $statuses[0]->value;
}
?>
--EXPECTF--
