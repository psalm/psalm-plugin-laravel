@php $ep = $endpoint; @endphp
@if($ep['body_params'] ?? false)
x
@endif
@foreach($items as $it)
@if($it['k'] ?? false) z @endif
@endforeach
@php $cls .= 'Controller'; @endphp
{{ new $cls() }}
@php preg_match('/(x)/', 'x', $matches); @endphp
{{ ucfirst($matches[1] ?? []) }}
