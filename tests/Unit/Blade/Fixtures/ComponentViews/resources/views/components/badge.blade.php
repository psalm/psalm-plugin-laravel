@php /** @psalm-trace $count */ $probe = [$count]; @endphp
@php $count = 'reassigned'; @endphp
@php ; @endphp
@php /** @psalm-trace $count */ $probe = [$count]; @endphp
