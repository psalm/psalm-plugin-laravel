--FILE--
<?php declare(strict_types=1);

enum Status: string {
    case Active = 'active';
    case Inactive = 'inactive';
}

function it_returns_specific_enum_type(\Illuminate\Http\Request $request): Status {
    $status = $request->enum('status', Status::class);

    /** @psalm-check-type-exact $status = Status|null */

    return $status ?? Status::Active;
}

// Note: enum($key, $class, $default) 3-param signature is Laravel 12+ only.
// Laravel 11 only has enum($key, $class) — skipped to support both versions.

function it_returns_typed_enums_array(\Illuminate\Http\Request $request): void {
    $statuses = $request->enums('statuses', Status::class);

    /** @psalm-check-type-exact $statuses = array<array-key, Status> */

    foreach ($statuses as $status) {
        echo $status->value;
    }
}
?>
--EXPECTF--
