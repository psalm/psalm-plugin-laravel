@props(['count' => 0, 'open' => false, 'items' => []])
{{ $count->value }}
{{ $items->all() }}
{{ $open->label }}
@if (is_array($count))
    <span>list</span>
@endif
