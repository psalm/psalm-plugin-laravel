@php
class BladeScopeCompiledClass1559
{
    public function instance(): void
    {
    }

    public static function callsSelfNonStatic(): void
    {
        self::instance();
    }

    public static function referencesThisStatically(): void
    {
        $this->instance();
    }
}
@endphp
