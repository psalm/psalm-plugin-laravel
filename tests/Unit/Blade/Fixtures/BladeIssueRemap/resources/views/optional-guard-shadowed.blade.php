<?php /** @var string $sh */ ?>
{{ $sh ?? '' }}
<?php $f = static function (string $sh): string {
    return $sh ?? '';
}; ?>
{{ $f('a') }}
