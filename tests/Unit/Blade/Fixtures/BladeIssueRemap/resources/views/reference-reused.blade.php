<h1>Items</h1>
@php
    $rows = [1, 2];
    foreach ($rows as &$row) {}
@endphp
<p>gap</p>
@php
    $row = 3;
@endphp
