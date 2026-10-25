@php
$callee = 'extract';
$callee(['x' => json_decode('1')]);
@endphp
@includeIsolated('dyn-leaf', ['x' => $x])
