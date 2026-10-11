{{-- @var string $title --}}
<?php /** @var string $label */ ?>
{{ $title ?? 'Untitled' }}
@php $label ??= 'Label'; @endphp
@if(isset($title)) {{ $title }} @endif
@isset($label) {{ $label }} @endisset
{{ isset($title) ? $title : 'none' }}
@if(!is_null($title)) set @endif
@php /** @param string $part */ function declaredGuardPart($part) { return $part ?? ''; } @endphp
@php $label .= "!"; @endphp
{{ $label ?? 'Label' }}
{{ $title
    ?? 'multi' }}
@if(!is_null($title)) {{ $title ?? '' }} @endif
<?php /** @var int $bound */ $bound = 1; ?>
@if(isset($bound)) set @endif
@php /** @var int $local */ $local = 1; @endphp
@if(isset($local)) set @endif
