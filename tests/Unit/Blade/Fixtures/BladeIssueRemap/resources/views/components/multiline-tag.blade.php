@props(['on' => false])
<x-alert
    class="a"
    {{ $attributes->merge(['class' => 'b']) }}
    @class(['active' => $on])
>
    @if ($on)
        Inner
    @endif
</x-alert>
