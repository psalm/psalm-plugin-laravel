<?php

declare(strict_types=1);

namespace AnnotateFixture;

use Illuminate\Contracts\View\View;

/** A fluent builder taking its view as a callback, the shape of an admin-panel action. */
final class Action
{
    public static function make(string $name): self
    {
        return new self();
    }

    public function modalContent(\Closure $content): self
    {
        return $this;
    }

    /** Arrow function body, inside an expression statement the chain walk declines. */
    public static function arrow(): void
    {
        self::make('qr')->modalContent(fn(Post $record): View => view('modal-arrow', ['text' => $record->slug]));
    }

    /** Closure statement body, inside a return statement the chain walk declines. */
    public static function closure(): self
    {
        return self::make('qr')->modalContent(static function (Post $record): View {
            return view('modal-closure', ['text' => $record->slug]);
        });
    }

    /** One closure nested in another, as a non-chain argument. */
    public static function nested(): void
    {
        self::make('qr')->modalContent(
            static fn(Post $record): array => \array_map(static fn(string $slug): View => view('modal-nested', ['text' => $slug]), [$record->slug]),
        );
    }

    /** @param array<string, string> $data */
    public static function openData(array $data): void
    {
        view('modal-open', ['text' => 'closed']);
        self::make('qr')->modalContent(static fn(): View => view('modal-open', $data));
    }

    /** The declined expression writes into the view the recorded callback returns. */
    public static function invokedInPlace(): View
    {
        return (static fn(): View => view('modal-invoked', ['text' => 'closed']))()->with('text', 42);
    }

    public static function rendered(): void
    {
        view('modal-rendered', ['text' => 'closed']);
        self::make('qr')->modalContent(static fn(): string => view('modal-rendered', ['text' => 42])->render());
    }
}
