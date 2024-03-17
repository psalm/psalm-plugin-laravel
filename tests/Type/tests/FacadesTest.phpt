--FILE--
<?php declare(strict_types=1);

function test_db_raw(): \Illuminate\Contracts\Database\Query\Expression {
    return \DB::raw(1);
}

function test_storage_disk_call_on_namespaced_facade(): \Illuminate\Contracts\Filesystem\Filesystem {
    return \Illuminate\Support\Facades\Storage::disk('resources');
}

function test_storage_disk_call_on_root_namespace_facade(): \Illuminate\Contracts\Filesystem\Filesystem {
    return \Storage::disk('resources');
}
?>
--EXPECTF--
