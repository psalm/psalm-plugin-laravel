<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\Annotate\AnnotationCollector;
use Psalm\Type;

#[CoversClass(AnnotationCollector::class)]
final class AnnotationCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        AnnotationCollector::reset();
    }

    protected function tearDown(): void
    {
        AnnotationCollector::reset();
    }

    #[Test]
    public function answers_the_type_a_single_resolved_producer_passed(): void
    {
        AnnotationCollector::record('home', ['title' => Type::getString()], true);

        $this->assertSame('string', AnnotationCollector::typeFor('home', 'title'));
    }

    #[Test]
    public function answers_the_type_every_producer_agrees_on(): void
    {
        AnnotationCollector::record('home', ['title' => Type::getString()], true);
        AnnotationCollector::record('home', ['title' => Type::getString()], true);

        $this->assertSame('string', AnnotationCollector::typeFor('home', 'title'));
    }

    #[Test]
    public function declines_when_two_producers_disagree(): void
    {
        AnnotationCollector::record('home', ['title' => Type::getString()], true);
        AnnotationCollector::record('home', ['title' => Type::getInt()], true);

        $this->assertNull(AnnotationCollector::typeFor('home', 'title'));
    }

    #[Test]
    public function collapses_literal_precision_so_two_literals_of_a_type_still_agree(): void
    {
        AnnotationCollector::record('home', ['count' => $this->literal(1)], true);
        AnnotationCollector::record('home', ['count' => $this->literal(2)], true);

        $this->assertSame('int', AnnotationCollector::typeFor('home', 'count'));
    }

    #[Test]
    public function declines_for_every_name_once_one_producer_of_the_view_was_unreadable(): void
    {
        AnnotationCollector::record('home', ['title' => Type::getString()], true);
        AnnotationCollector::record('home', [], false);

        $this->assertNull(
            AnnotationCollector::typeFor('home', 'title'),
            'an open data set can carry the same key with another type',
        );
    }

    #[Test]
    public function declines_for_a_view_no_producer_resolved(): void
    {
        $this->assertNull(AnnotationCollector::typeFor('home', 'title'));
    }

    #[Test]
    public function declines_for_a_name_no_producer_passed(): void
    {
        AnnotationCollector::record('home', ['title' => Type::getString()], true);

        $this->assertNull(AnnotationCollector::typeFor('home', 'body'));
    }

    #[Test]
    public function keeps_a_numeric_view_name_addressable_as_a_string(): void
    {
        // PHP casts a numeric-string array key to int, and `123.blade.php` is a legal template.
        AnnotationCollector::record('123', ['title' => Type::getString()], true);

        $this->assertSame('string', AnnotationCollector::typeFor('123', 'title'));
    }

    #[Test]
    public function declines_a_type_that_does_not_survive_a_parse_round_trip(): void
    {
        // An anonymous class prints an id carrying its defining file and line, which the
        // `{{-- @var --}}` reader on the next run cannot parse back.
        $anonymous = new Type\Union([new Type\Atomic\TNamedObject('Foo@anonymous/var/www/x.php:3$0')]);

        AnnotationCollector::record('home', ['title' => $anonymous], true);

        $this->assertNull(AnnotationCollector::typeFor('home', 'title'));
    }

    #[Test]
    public function forgets_everything_on_reset(): void
    {
        AnnotationCollector::record('home', ['title' => Type::getString()], true);
        AnnotationCollector::reset();

        $this->assertNull(AnnotationCollector::typeFor('home', 'title'));
    }

    #[Test]
    public function declines_a_type_whose_id_would_terminate_the_blade_comment(): void
    {
        // `{{-- @var array{'--}}': string} $payload --}}` closes at the key, and Laravel renders the
        // rest of the declaration into the page.
        $keyed = new Type\Union([new Type\Atomic\TKeyedArray(['--}}' => Type::getString()])]);

        AnnotationCollector::record('home', ['payload' => $keyed], true);

        $this->assertNull(AnnotationCollector::typeFor('home', 'payload'));
    }

    #[Test]
    public function declines_a_template_parameter_even_though_its_id_parses(): void
    {
        // `T` parses, but as a class named T — a different type than the one observed.
        $param = new Type\Atomic\TTemplateParam('T', Type::getMixed(), 'fn-foo');

        AnnotationCollector::record('home', ['row' => new Type\Union([$param])], true);

        $this->assertNull(AnnotationCollector::typeFor('home', 'row'));
    }

    #[Test]
    public function declines_a_template_parameter_nested_inside_a_generic(): void
    {
        $param = new Type\Union([new Type\Atomic\TTemplateParam('T', Type::getMixed(), 'fn-foo')]);
        $generic = new Type\Atomic\TGenericObject('Illuminate\Support\Collection', [Type::getInt(), $param]);

        AnnotationCollector::record('home', ['rows' => new Type\Union([$generic])], true);

        $this->assertNull(AnnotationCollector::typeFor('home', 'rows'));
    }

    #[Test]
    public function marks_a_view_unreadable_when_a_rendering_shape_could_not_be_resolved(): void
    {
        // `$v = view('home', [...])` is declined by the chain walk, so its data is invisible; the
        // types the readable call sites agreed on are only part of the picture.
        AnnotationCollector::record('home', ['title' => Type::getString()], true);
        AnnotationCollector::markUnreadable('home');

        $this->assertNull(AnnotationCollector::typeFor('home', 'title'));
    }

    #[Test]
    public function reports_a_name_a_provably_closed_call_site_leaves_out(): void
    {
        // Declaring it would report MissingViewVariable at that very call site, which passes a
        // provably closed data set without it.
        AnnotationCollector::record('home', ['title' => Type::getString()], true);
        AnnotationCollector::record('home', [], true);

        $this->assertTrue(AnnotationCollector::isOptional('home', 'title'));
    }

    #[Test]
    public function does_not_report_a_name_every_closed_call_site_passes(): void
    {
        AnnotationCollector::record('home', ['title' => Type::getString()], true);
        AnnotationCollector::record('home', ['title' => Type::getString()], true);

        $this->assertFalse(AnnotationCollector::isOptional('home', 'title'));
    }

    #[Test]
    public function does_not_report_a_name_when_no_call_site_proved_its_key_set_closed(): void
    {
        AnnotationCollector::record('home', [], false);

        $this->assertFalse(AnnotationCollector::isOptional('home', 'title'));
    }

    /** Built directly: Type::getInt($value) reaches for a Config singleton no unit test boots. */
    private function literal(int $value): Type\Union
    {
        return new Type\Union([new Type\Atomic\TLiteralInt($value)]);
    }
}
