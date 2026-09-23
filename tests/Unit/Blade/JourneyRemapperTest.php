<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\CodeLocation;
use Psalm\CodeLocation\Raw;
use Psalm\LaravelPlugin\Blade\JourneyRemapper;
use Psalm\LaravelPlugin\Blade\MarkerComment;
use Psalm\LaravelPlugin\Blade\PsalmBridge;
use Psalm\LaravelPlugin\Blade\ShadowEntry;
use Psalm\LaravelPlugin\Blade\ShadowTarget;

#[CoversClass(JourneyRemapper::class)]
#[CoversClass(PsalmBridge::class)]
#[CoversClass(ShadowTarget::class)]
final class JourneyRemapperTest extends TestCase
{
    private const SHADOW = '/app/.cache/blade-shadows/abc.php';

    private const SHADOW_NAME = '.cache/blade-shadows/abc.php';

    private const TEMPLATE = '/app/resources/views/profile.blade.php';

    private const TEMPLATE_NAME = 'resources/views/profile.blade.php';

    private const TEMPLATE_SOURCE = "<div>\n  <p>first</p>\n  <p>second</p>\n</div>\n";

    /** @param array<int, int> $lineMap shadow line => template line */
    private function resolver(array $lineMap): \Closure
    {
        $target = new ShadowTarget(
            new ShadowEntry(self::TEMPLATE, $lineMap, []),
            self::TEMPLATE_SOURCE,
            self::TEMPLATE_NAME,
            false,
            MarkerComment::prefixFor(self::TEMPLATE_SOURCE),
        );

        return static fn(string $path): ?ShadowTarget => $path === self::SHADOW ? $target : null;
    }

    private function location(string $path, string $name, int $line): Raw
    {
        return new Raw(\str_repeat("\n", $line - 1), $path, $name, $line - 1, $line - 1);
    }

    /** @return array{location: ?CodeLocation, label: string, entry_path_type: string} */
    private function step(?CodeLocation $location, string $label = 'call to echo'): array
    {
        return ['location' => $location, 'label' => $label, 'entry_path_type' => ''];
    }

    /**
     * @param list<array{location: ?CodeLocation, label: string, entry_path_type: string}> $journey
     * @param array<int, int>                                                              $lineMap
     *
     * @return array{journey: list<array{location: ?CodeLocation, label: string, entry_path_type: string}>, journey_text: string}|null
     */
    private function remap(array $journey, array $lineMap, string $journeyText = '', int $issueLine = 9): ?array
    {
        return JourneyRemapper::remap(
            $journey,
            $journeyText,
            $this->location(self::SHADOW, self::SHADOW_NAME, $issueLine),
            $this->resolver($lineMap),
        );
    }

    #[Test]
    public function it_moves_a_step_inside_a_shadow_onto_the_template_line(): void
    {
        $remapped = $this->remap([$this->step($this->location(self::SHADOW, self::SHADOW_NAME, 9))], [9 => 3]);

        $this->assertNotNull($remapped);
        $location = $remapped['journey'][0]['location'];
        $this->assertInstanceOf(CodeLocation::class, $location);
        $this->assertSame(self::TEMPLATE, $location->file_path);
        $this->assertSame(self::TEMPLATE_NAME, $location->file_name);
        $this->assertSame(3, $location->getLineNumber());
        $this->assertSame('call to echo', $remapped['journey'][0]['label']);
    }

    #[Test]
    public function it_leaves_a_step_outside_any_shadow_alone(): void
    {
        $original = $this->location('/app/Http/Controllers/ProfileController.php', 'app/Http/Controllers/ProfileController.php', 4);

        $remapped = $this->remap([$this->step($original)], [9 => 3]);

        $this->assertNotNull($remapped);
        // Identity, not equality: an ordinary application file is already reported where the user
        // can find it, so the step must not even be rebuilt.
        $this->assertSame($original, $remapped['journey'][0]['location']);
    }

    #[Test]
    public function it_leaves_a_step_with_no_location_alone(): void
    {
        // A stubbed taint source contributes a labelled node with no expression behind it.
        $remapped = $this->remap([$this->step(null, 'Illuminate\Http\Request::input')], [9 => 3]);

        $this->assertNotNull($remapped);
        $this->assertNull($remapped['journey'][0]['location']);
        $this->assertSame('Illuminate\Http\Request::input', $remapped['journey'][0]['label']);
    }

    #[Test]
    public function a_step_on_a_line_that_maps_to_no_template_line_lands_on_line_one(): void
    {
        // Prelude lines map to 0, and a shadow line missing from the map is the same story.
        $remapped = $this->remap([$this->step($this->location(self::SHADOW, self::SHADOW_NAME, 2))], [2 => 0]);

        $this->assertNotNull($remapped);
        $location = $remapped['journey'][0]['location'];
        $this->assertInstanceOf(CodeLocation::class, $location);
        $this->assertSame(1, $location->getLineNumber());
    }

    #[Test]
    public function it_rewrites_every_shadow_descriptor_in_the_journey_text(): void
    {
        // The last descriptor is a sink-side node: journey_text describes hops the journey array
        // stops short of, which is why the text is rewritten rather than regenerated.
        $text = 'Illuminate\Http\Request::input'
            . ' -> call to echo (' . self::SHADOW_NAME . ':9:36)'
            . ' -> echo (' . self::SHADOW_NAME . ':12:41)';

        $remapped = $this->remap([$this->step($this->location(self::SHADOW, self::SHADOW_NAME, 9))], [9 => 3, 12 => 4], $text);

        $this->assertNotNull($remapped);
        $this->assertSame(
            'Illuminate\Http\Request::input'
                . ' -> call to echo (' . self::TEMPLATE_NAME . ':3:36)'
                . ' -> echo (' . self::TEMPLATE_NAME . ':4:41)',
            $remapped['journey_text'],
        );
    }

    #[Test]
    public function it_rewrites_the_journey_text_of_a_shadow_no_journey_step_names(): void
    {
        // Every journey step sits in ordinary code, so the shadow is only reachable through the
        // issue's own location — which is what seeds the candidate list.
        $text = 'input (app/Http/Controllers/ProfileController.php:4:12)'
            . ' -> echo (' . self::SHADOW_NAME . ':9:36)';

        $remapped = $this->remap([], [9 => 3], $text);

        $this->assertNotNull($remapped);
        $this->assertSame(
            'input (app/Http/Controllers/ProfileController.php:4:12)'
                . ' -> echo (' . self::TEMPLATE_NAME . ':3:36)',
            $remapped['journey_text'],
        );
    }

    #[Test]
    public function it_declines_when_the_template_has_no_such_line(): void
    {
        $this->assertNull($this->remap([$this->step($this->location(self::SHADOW, self::SHADOW_NAME, 9))], [9 => 99]));
    }

    #[Test]
    public function it_remaps_a_shadow_step_even_when_the_issue_itself_sits_outside_any_shadow(): void
    {
        // #1519: the sink is ordinary application code (the issue's own location), but a journey
        // step passed through a template shadow on its way there.
        $appLocation = $this->location('/app/Sink.php', 'app/Sink.php', 13);

        $remapped = JourneyRemapper::remap(
            [$this->step($this->location(self::SHADOW, self::SHADOW_NAME, 9))],
            '',
            $appLocation,
            $this->resolver([9 => 3]),
        );

        $this->assertNotNull($remapped);
        $location = $remapped['journey'][0]['location'];
        $this->assertInstanceOf(CodeLocation::class, $location);
        $this->assertSame(self::TEMPLATE, $location->file_path);
        $this->assertSame(3, $location->getLineNumber());
    }

    #[Test]
    public function it_remaps_a_journey_that_crosses_two_shadows(): void
    {
        // template -> @include'd template -> PHP: resolveTargets() keys by file NAME and
        // remapSteps() by path, so two distinct shadow files both need to resolve.
        $includeShadow = '/app/.cache/blade-shadows/def.php';
        $includeShadowName = '.cache/blade-shadows/def.php';
        $includeTemplate = '/app/resources/views/included.blade.php';
        $includeTemplateName = 'resources/views/included.blade.php';
        $includeSource = "<span>\n  x\n</span>\n";

        $outerTarget = new ShadowTarget(new ShadowEntry(self::TEMPLATE, [9 => 3], []), self::TEMPLATE_SOURCE, self::TEMPLATE_NAME, false, MarkerComment::prefixFor(self::TEMPLATE_SOURCE));
        $includeTarget = new ShadowTarget(new ShadowEntry($includeTemplate, [5 => 2], []), $includeSource, $includeTemplateName, false, MarkerComment::prefixFor($includeSource));

        $resolve = static fn(string $path): ?ShadowTarget => match ($path) {
            self::SHADOW => $outerTarget,
            $includeShadow => $includeTarget,
            default => null,
        };

        $journey = [
            $this->step($this->location(self::SHADOW, self::SHADOW_NAME, 9), 'call to echo'),
            $this->step($this->location($includeShadow, $includeShadowName, 5), 'call to include'),
        ];

        $remapped = JourneyRemapper::remap($journey, '', $this->location(self::SHADOW, self::SHADOW_NAME, 9), $resolve);

        $this->assertNotNull($remapped);
        $first = $remapped['journey'][0]['location'];
        $second = $remapped['journey'][1]['location'];
        $this->assertInstanceOf(CodeLocation::class, $first);
        $this->assertInstanceOf(CodeLocation::class, $second);
        $this->assertSame(self::TEMPLATE, $first->file_path);
        $this->assertSame(3, $first->getLineNumber());
        $this->assertSame($includeTemplate, $second->file_path);
        $this->assertSame(2, $second->getLineNumber());
    }

    #[Test]
    public function it_passes_a_shadow_free_journey_straight_through(): void
    {
        $journey = [$this->step($this->location('/app/Http/Controllers/ProfileController.php', 'app/Http/Controllers/ProfileController.php', 4))];
        $text = 'input (app/Http/Controllers/ProfileController.php:4:12)';

        $remapped = JourneyRemapper::remap(
            $journey,
            $text,
            $this->location('/app/Http/Controllers/ProfileController.php', 'app/Http/Controllers/ProfileController.php', 4),
            $this->resolver([9 => 3]),
        );

        $this->assertSame(['journey' => $journey, 'journey_text' => $text], $remapped);
    }
}
