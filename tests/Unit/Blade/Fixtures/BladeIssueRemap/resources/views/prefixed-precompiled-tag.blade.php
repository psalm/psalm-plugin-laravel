@if (\random_int(0, 1) === 1)
    <foo:alert
        class="a"
        type="info"
    >
        Inner
    </foo:alert>
@else
    <foo:alert
        class="b"
    />
@endif
<p>{{ (new \BladeIssueRemapFixture\Greeter())->nmae }}</p>
