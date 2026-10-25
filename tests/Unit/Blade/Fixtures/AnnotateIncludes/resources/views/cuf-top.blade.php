@php
call_user_func('extract', ['x' => json_decode('1')]);
@endphp
@includeIsolated('cuf-leaf', ['x' => $x])
