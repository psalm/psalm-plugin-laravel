<div>
  {{ (new \BladeIssueRemapFixture\LivewireMountTarget())->mount('a', 'b', 'c', 'd') }}
  {!! (new \BladeIssueRemapFixture\LivewireMountTarget())->mount('e', 'f', 'g', 'h') !!}
  @php
  (new \BladeIssueRemapFixture\LivewireMountTarget())->mount('i', 'j', 'k', 'l');
  @endphp
</div>
