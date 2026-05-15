<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

/**
 * Marker interface for the argument-shape descriptors consumed by
 * {@see BladeAwareViewTaintHandler}.
 *
 * Each Laravel API the handler dispatches on (`Factory::make`,
 * `Factory::first`, `Factory::renderEach`, `View::with`, `Mailable::view`,
 * `Content::__construct`, etc.) accepts a different argument layout. Rather
 * than bloat one descriptor with optional fields for every shape, the handler
 * uses a discriminated union: distinct implementations of this interface
 * encode each call shape, and the dispatcher branches on the concrete type
 * via `match (true) { ... }`.
 *
 * Implementations are intentionally narrow value objects (one or two int
 * fields each) and are constructed once at boot from
 * {@see BladeAwareViewTaintHandler::buildMethodSpecs()}. There is no runtime
 * polymorphism in the dispatch hot path; every concrete instance is `final`
 * and the `match` shape is exhaustive.
 *
 * PHP lacks a `sealed` keyword, but the interface is `@internal` to the
 * plugin and the dispatcher's match arms enumerate every implementation. New
 * implementations require updating the dispatcher in lockstep.
 *
 * @internal
 *
 * @psalm-immutable
 */
interface ViewBindingSinkSpec {}
