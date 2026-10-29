@php
$x ??= json_decode('{}');
@endphp
@includeIsolated('coalesce-leaf', ['x' => $x])
