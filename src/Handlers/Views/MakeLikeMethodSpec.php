<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

/**
 * Argument-shape descriptor for `Factory::make()`-style methods consumed by
 * {@see BladeAwareViewTaintHandler}.
 *
 * Covers every call shape with one or more string-name view arguments, a
 * single associative-array data argument, and optionally a second mergeData
 * argument. Laravel methods using this shape include:
 *
 *  - `\Illuminate\View\Factory::make($view, $data = [], $mergeData = [])`
 *    and the contract method `\Illuminate\Contracts\View\Factory::make`
 *  - `\Illuminate\View\Factory::renderWhen($cond, $view, $data, $mergeData)`
 *    and the symmetric `renderUnless` (single view arg at index 1)
 *  - `\Illuminate\Routing\ResponseFactory::view($view, $data, $status, $headers)`
 *    plus its contract (single view arg, single data arg, no mergeData)
 *  - `\Illuminate\View\View::nest($key, $view, $data = [])` (view at index 1)
 *  - `\Illuminate\Mail\Mailable::view($view, array $data = [])` and the
 *    `markdown` / `text` siblings; `MailMessage::view` / `markdown` / `text`
 *    on `\Illuminate\Notifications\Messages\MailMessage`
 *  - `\Illuminate\Mail\Mailables\Content::__construct(?$view, ?$html, ?$text,
 *    $markdown, $with, ?$htmlString)` — three view-name slots (view at 0,
 *    text at 2, markdown at 3) sharing one $with data slot
 *
 * The handler dispatches one sink-installation pass per `viewArgIndices`
 * entry. Each pass treats the named view independently, so a single Content
 * constructor produces one TaintedHtml report per literal view slot whose
 * scanner safety record says the bound key is unsafe.
 *
 * @internal
 *
 * @psalm-immutable
 */
final readonly class MakeLikeMethodSpec implements ViewBindingSinkSpec
{
    /**
     * @param non-empty-list<int> $viewArgIndices argument positions carrying a literal-string view name.
     *                                            Single-view methods pass a one-element list (e.g. `[0]`);
     *                                            Content's three-slot constructor passes `[0, 2, 3]`.
     * @param int                 $dataArgIndex   position of the shared data array. Every view slot in
     *                                            {@see $viewArgIndices} dispatches against this single
     *                                            data argument.
     * @param ?int                $mergeDataArgIndex optional secondary data argument; only
     *                                              `Factory::make` / `renderWhen` / `renderUnless` use it.
     *                                              Other shapes pass null.
     */
    public function __construct(
        public array $viewArgIndices,
        public int $dataArgIndex,
        public ?int $mergeDataArgIndex,
    ) {}
}
