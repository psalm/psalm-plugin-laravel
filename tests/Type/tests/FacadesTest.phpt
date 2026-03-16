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

function test_route_get(): \Illuminate\Routing\Route {
    return \Route::get('/test', fn() => 'ok');
}

function test_cache_get(): mixed {
    return \Cache::get('key');
}

function test_auth_check(): bool {
    return \Auth::check();
}

function test_config_get(): mixed {
    return \Config::get('app.name');
}

function test_fqcn_cache(): mixed {
    return \Illuminate\Support\Facades\Cache::get('key');
}

function test_str_alias(): bool {
    return \Str::startsWith('hello', 'he');
}

function test_arr_alias(): array {
    return \Arr::wrap('value');
}
?>
--EXPECTF--
