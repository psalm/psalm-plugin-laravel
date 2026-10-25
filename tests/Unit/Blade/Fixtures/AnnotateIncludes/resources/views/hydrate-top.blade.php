@php
use function extract as hydrate;
hydrate(['x' => json_decode('1')]);
@endphp
@includeIsolated('hydrate-leaf', ['x' => $x])
