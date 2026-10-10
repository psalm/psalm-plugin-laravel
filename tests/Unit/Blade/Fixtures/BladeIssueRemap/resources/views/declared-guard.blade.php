{{-- @var string $title --}}
<?php /** @var string $label */ ?>
{{ $title ?? 'Untitled' }}
@php $label ??= 'Label'; @endphp
@if(isset($title)) {{ $title }} @endif
@isset($label) {{ $label }} @endisset
{{ isset($title) ? $title : 'none' }}
@if(!is_null($title)) set @endif
@php /** @param string $part */ function declaredGuardPart($part) { return $part ?? ''; } @endphp
@php $label = 42; @endphp
{{ $label ?? 'Label' }}
{{ $title
    ?? 'multi' }}
@if(!is_null($title)) {{ $title ?? '' }} @endif
