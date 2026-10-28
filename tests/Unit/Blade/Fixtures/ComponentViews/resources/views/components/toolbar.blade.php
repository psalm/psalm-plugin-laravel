@php /** @psalm-trace $withTone $pad $echo */ $probe = [$withTone, $pad, $echo]; @endphp
@php $target = null; $outSelf($target); $nativeSelf($target); @endphp
@php /** @psalm-trace $target */; @endphp
@php $f = $fresh(); $m = $me(); /** @psalm-trace $fresh $f $me $m */; @endphp
