<?php

declare(strict_types=1);

namespace BladeViewDataTaintFixture;

use Illuminate\Http\Response;
use Illuminate\Routing\ResponseFactory;
use Illuminate\View\Factory;

/**
 * A userland view factory whose `make()` renders another view than the name its caller passed.
 *
 * @psalm-suppress UnusedClass
 */
final class OverridingFactory extends Factory
{
    /**
     * @param string                                         $view
     * @param \Illuminate\Contracts\Support\Arrayable|array $data
     * @param array                                          $mergeData
     * @return \Illuminate\Contracts\View\View
     */
    #[\Override]
    public function make($view, $data = [], $mergeData = [])
    {
        return parent::make('literal', $data, $mergeData);
    }
}

/**
 * A userland view factory that overrides nothing `make()` reaches, so Laravel's own method still runs.
 *
 * @psalm-suppress UnusedClass
 */
final class PlainFactory extends Factory {}

/**
 * A response whose `with()` never touches the view's data.
 *
 * @psalm-suppress UnusedClass
 */
final class QuietResponse extends Response
{
    /** @psalm-suppress UnusedParam, MissingPureAnnotation, PossiblyUnusedMethod */
    public function with(string $key, mixed $value = null): static
    {
        return $this;
    }
}

/**
 * A userland response factory whose `view()` binds no template at all.
 *
 * @psalm-suppress UnusedClass
 */
final class OverridingResponses extends ResponseFactory
{
    /**
     * @param \Illuminate\Contracts\View\View|string|array $view
     * @param array        $data
     * @param int          $status
     * @param array        $headers
     * @return QuietResponse
     *
     * @psalm-suppress MissingPureAnnotation
     */
    #[\Override]
    public function view($view, $data = [], $status = 200, array $headers = [])
    {
        return new QuietResponse('', $status, $headers);
    }
}
