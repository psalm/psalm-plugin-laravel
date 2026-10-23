{{ $a->foo() }}
{{ $b->c->d() }}
{{ strlen($e) }}
<?php /** @var array $data */ ?>
{{ $data['x']->foo() }}
@foreach($items as $item) {{ $item->name }} @endforeach
@php $local = $f['k']; @endphp
{{ $local->bar() }}
@foreach($g->list as $w) {{ $w }} @endforeach
@php $decoded = json_decode('1'); @endphp
{{ $data['x']?->nulls() }}
{{ $h?->nulls() }}
{{ $data['x']?->first() /* c */ ?->last() }}
