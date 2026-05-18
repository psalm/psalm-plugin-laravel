<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;

/**
 * Subclass of {@see BladeCompiler} that swaps two behaviours so the compiler is
 * usable as a pure source-to-PHP transformer for static analysis.
 *
 * Two upstream behaviours are unsuitable for our analysis-time use:
 *
 * 1. {@see BladeCompiler::compileComponentTags()} instantiates a
 *    {@see \Illuminate\View\Compilers\ComponentTagCompiler}, which calls
 *    {@see \Illuminate\Container\Container::make()} on the discovered
 *    component class. At analysis time the user's components are not
 *    bound in the container the plugin booted, so the call throws
 *    {@see \Illuminate\Contracts\Container\BindingResolutionException}.
 *    We do not want to resolve components — we want to detect them and
 *    surface {@see BladeUncertaintyReason::ComponentTag}. This subclass
 *    overrides the method to flip {@see $sawComponentTag} when a `<x-foo>`
 *    or `<x:foo>` tag is present, and leaves the markup untouched.
 *    The scanner reads the flag after `compileString()` returns.
 *
 * 2. {@see BladeCompiler::restoreRawContent()} fails to substitute the
 *    placeholder that {@see BladeCompiler::storePhpBlocks()} inserted for
 *    `@php ... @endphp` blocks when the template has `{{` (or `{!!` or
 *    `{{{`) immediately following `@endphp`. The placeholder is
 *    `@__raw_block_N__@` (note the trailing `@`); compileEchos' regex
 *    `(@)?{{...}}` matches the placeholder's trailing `@` as an escape
 *    marker, emits the inner `{{ ... }}` literally, and consumes the `@`.
 *    `restoreRawContent`'s regex requires the `@` suffix, so it cannot
 *    match and the placeholder leaks into the compiled output. We override
 *    the regex with an `@?` (optional suffix) so restoration tolerates the
 *    eaten `@`. The replacement content is still correct PHP; only the
 *    placeholder framing is patched.
 *
 *    Upstream fix in flight at laravel/framework#60136. The override and
 *    the source preprocessing in {@see compileBladeSource()} can be removed
 *    once the fix ships in a supported Laravel release; until then this
 *    subclass closes the gap locally.
 *
 * The class is constructed with a fresh {@see Filesystem} and a writable
 * cache path; we never actually write cache files because we only ever call
 * `compileString()`, but the {@see BladeCompiler} constructor requires both.
 *
 * @internal
 */
final class PsalmBladeCompiler extends BladeCompiler
{
    private bool $sawComponentTag = false;

    public function __construct(?Filesystem $files = null, ?string $cachePath = null)
    {
        parent::__construct(
            $files ?? new Filesystem(),
            $cachePath ?? \sys_get_temp_dir(),
        );

        /*
         * Parent base classes declare these as `protected $foo;` without
         * defaults, which makes Psalm's `PropertyNotSetInConstructor` fire
         * on the subclass. The parent compiler lazily writes them as it
         * processes a template; we seed them with empty strings here to
         * satisfy the analysis without changing runtime behaviour.
         */
        $this->path = '';
        $this->lastSection = '';
        $this->lastFragment = '';
    }

    /**
     * Resets the per-compile state and runs the parent {@see compileString()}.
     *
     * `BladeCompiler` keeps `$rawBlocks` and `$footer` as instance state
     * mutated by every `compileString` call. The parent constructor zeroes
     * `$footer` at entry but never resets `$rawBlocks`, so a previous run's
     * placeholders can collide on the next run. We reset both up front and
     * additionally clear our own `sawComponentTag` flag so each scan starts
     * with a clean slate.
     *
     * Adds a single defensive preprocessing pass to insert whitespace between
     * `@endphp` / `@endverbatim` and an immediately following `{` (which
     * starts a `{{`, `{!!`, or `{{{` echo) or `@` (which starts an adjacent
     * raw block). Without the space, `storeUncompiledBlocks` produces
     * `@__raw_block_N__@{{...`, and the inner compileEchos regex
     * `(@)?{{...}}` consumes the placeholder's trailing `@` as an
     * escape-brace marker, leaving the echo uncompiled and the placeholder
     * unrestorable. The inserted whitespace breaks the adjacency without
     * changing the rendered output (Blade collapses HTML whitespace at
     * render time).
     *
     * @param string $value Blade template source
     *
     * @return string compiled PHP
     */
    public function compileBladeSource(string $value): string
    {
        $this->sawComponentTag = false;
        $this->resetRawBlocks();

        $value = \preg_replace(
            '/(@end(?:php|verbatim))(?=[{@])/',
            '$1 ',
            $value,
        ) ?? $value;

        return $this->compileString($value);
    }

    public function sawComponentTag(): bool
    {
        return $this->sawComponentTag;
    }

    /**
     * Detect component tags without trying to resolve their classes.
     *
     * Pattern matches both `<x-foo>` and `<x:foo>` plus the directive forms
     * `@component` and `@slot`. Matches the Laravel parser's accepted
     * surface (see {@see \Illuminate\View\Compilers\ComponentTagCompiler}).
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    protected function compileComponentTags($value)
    {
        if (\preg_match('/<x[-:][a-zA-Z][\w.:-]*|@(?:component|slot)\b/', $value) === 1) {
            $this->sawComponentTag = true;
        }

        return $value;
    }

    /**
     * Tolerant restoration: accepts both `@__raw_block_N__@` (well-formed)
     * and `@__raw_block_N__` (trailing `@` eaten by compileEchos when the
     * template has `{{` immediately following `@endphp`).
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    protected function restoreRawContent($result)
    {
        /** @var array<int, string> $rawBlocks */
        $rawBlocks = $this->rawBlocks;

        $restored = \preg_replace_callback(
            '/@__raw_block_(\d+)__@?/',
            /** @param array<array-key, string> $matches */
            static function (array $matches) use ($rawBlocks): string {
                $index = (int) ($matches[1] ?? '0');

                return $rawBlocks[$index] ?? ($matches[0] ?? '');
            },
            $result,
        );

        $this->rawBlocks = [];

        return $restored ?? $result;
    }

    /**
     * `rawBlocks` is `protected` on the parent, so this writer lives on the
     * subclass purely so callers don't reach into the parent's internals via
     * reflection. Called from {@see compileBladeSource()} before every run.
     *
     * @psalm-external-mutation-free
     */
    private function resetRawBlocks(): void
    {
        $this->rawBlocks = [];
    }
}
