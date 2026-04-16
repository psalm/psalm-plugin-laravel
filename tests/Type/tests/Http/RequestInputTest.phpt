--SKIPIF--
<?php require getcwd() . '/vendor/autoload.php'; if (!\Composer\InstalledVersions::satisfies(new \Composer\Semver\VersionParser(), 'laravel/framework', '^12.0.0')) { echo 'skip requires Laravel 12+'; }
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

function it_returns_enum_with_default(\Illuminate\Http\Request $request): Status {
    $status = $request->enum('status', Status::class, Status::Active);

    /** @psalm-check-type-exact $status = Status */

    return $status;
}

function it_returns_typed_enums_array(\Illuminate\Http\Request $request): void {
    $statuses = $request->enums('statuses', Status::class);

    /** @psalm-check-type-exact $statuses = array<array-key, Status> */

    foreach ($statuses as $status) {
        echo $status->value;
    }
}
?>
--EXPECTF--
