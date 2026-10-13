@props(['title', 'none' => null, 'eol' => PHP_EOL, 'upper' => strtoupper('a'), 'joined' => 'a' . 'b', 'plus' => +1, 'max' => -PHP_INT_MAX, 'copied' => $fallback, 'line' => "line {$fallback}"])
@php /** @psalm-trace $title $none $eol $upper $joined $plus $max $copied $line */ $probe = [$title, $none, $eol, $upper, $joined, $plus, $max, $copied, $line]; @endphp
