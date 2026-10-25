@php
function rewrite_byref(mixed &$value): void {
    $value = json_decode('1');
}
rewrite_byref($x);
@endphp
@includeIsolated('byref-leaf', ['x' => $x])
