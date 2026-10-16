<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Post;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class ShowPost extends Component
{
    public Post $post;

    public int $count = 0;

    /** @var list<string> */
    public array $tags = [];

    public $untyped;

    public static int $instances = 0;

    protected string $hidden = '';

    private string $secret = '';

    public function save(): bool
    {
        return $this->secret === $this->hidden;
    }

    protected function helper(): int
    {
        return 1;
    }

    public function render(): View
    {
        // `count` reaches the view from both render() and the properties; the property wins.
        return view('livewire.show-post', ['count' => 'render', 'extra' => 1])->layout('layouts.app')->title('Post');
    }
}
