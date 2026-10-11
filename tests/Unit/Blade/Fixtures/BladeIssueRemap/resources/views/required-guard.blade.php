{{-- @var string $title --}}
<?php /** @var string $label */ ?>
{{-- @var string|null $subtitle = null --}}
<?php /** @var string|null $note = null */ ?>
{{ $title ?? 'Untitled' }}
@php $label ??= 'Label'; @endphp
@if(isset($title)) {{ $title }} @endif
{{ $subtitle ?? '' }}{{ $note ?? '' }}
@php $note ??= 'Note'; @endphp
