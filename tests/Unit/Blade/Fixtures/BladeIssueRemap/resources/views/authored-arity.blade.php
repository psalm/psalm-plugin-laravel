<div>
  {{ (new \BladeIssueRemapFixture\LivewireMountTarget())->mount('a', 'b', 'c', 'd') }}
  {!! (new \BladeIssueRemapFixture\LivewireMountTarget())->mount('e', 'f', 'g', 'h') !!}
  @php
  (new \BladeIssueRemapFixture\LivewireMountTarget())->mount('i', 'j', 'k', 'l');
  @endphp
  {{ (new \BladeIssueRemapFixture\LivewireMountTarget())->mount("$label(", 'm', 'n', 'o') }}
  {{ (new \BladeIssueRemapFixture\LivewireMountTarget())->mount('p', {{-- why --}} 'q', 'r', 's') }}
  {{ (new \BladeIssueRemapFixture\LivewireMountTarget())->mount('@@foo', 'u', 'v', 'w') }}
  @php
  (new \BladeIssueRemapFixture\LivewireMountTarget())->mount(
      'aa',
      'bb',
      'cc',
      'dd',
  );
  @endphp
  @php
  strlen(
      'a',
      'b',
  );
  @endphp
</div>
