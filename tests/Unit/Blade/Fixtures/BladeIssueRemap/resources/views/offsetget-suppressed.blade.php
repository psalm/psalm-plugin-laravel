<?php /** @var array<string, mixed>|\ArrayAccess $x */ ?>
<?php /** @var array<string, \ArrayAccess|array<string, string>> $rows */ ?>
@php $u = rand() ? $unknown : new \ArrayObject(); @endphp
{{ isset($x['k']) ? $x['k']['n'] : '' }}
{{ $rows['k']['n'] }}
{{ $u['k'] }}
{{ isset($u['k']) ? 1 : 0 }}
@php $x['q'] = 1; @endphp
