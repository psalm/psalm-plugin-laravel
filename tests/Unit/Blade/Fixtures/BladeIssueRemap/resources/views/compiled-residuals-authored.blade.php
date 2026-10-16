<div>
  @php $authorSplit = function (string $name): array { return [$name]; }; @endphp
  {{ 'tab'->value() }}
  @php $__authorFlag = \getenv('X'); @endphp
  @if ($__authorFlag) set @endif
  @php $authorHtml = new \Illuminate\Support\HtmlString(ob_get_clean()); @endphp
  {{ $authorSplit('x')[0] }} {{ $authorHtml }}
</div>
