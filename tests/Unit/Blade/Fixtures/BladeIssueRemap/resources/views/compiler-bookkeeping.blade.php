<x-alert>Hi</x-alert>

@foreach ($items as $item)
    <p>{{ $item }}</p>
@endforeach

@switch($items)
    @case(1)
        First

        @break
    @case(2)
        Second
        @break
    @default
        Default
@endswitch
