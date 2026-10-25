@php
$alias =& $x;
$alias = json_decode('{}');
@endphp
@includeIsolated('ref-alias-leaf', ['x' => $x])
