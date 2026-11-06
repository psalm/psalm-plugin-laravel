<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Post;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class ShowPost extends Component
{
    protected const LIMIT = 10;

    public Post $post;

    public function save(): void {}

    protected function helper(): string
    {
        return '';
    }

    /** A statement before the return: Livewire's render() data never shadows a property. */
    public function render(): View
    {
        $heading = \strtoupper('post');

        return view('livewire.show-post', ['heading' => $heading]);
    }
}
