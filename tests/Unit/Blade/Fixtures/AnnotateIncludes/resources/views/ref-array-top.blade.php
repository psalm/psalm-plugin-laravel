@php
$refs = [&$x];
@endphp
@includeIsolated('ref-array-leaf', ['x' => $x])
