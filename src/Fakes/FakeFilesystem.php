<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Fakes;

use Illuminate\Filesystem\Filesystem;

final class FakeFilesystem extends Filesystem
{
    private ?string $destination = '';

    /**
     * Write the contents of a file.
     *
     * @param  string  $path
     * @param  string  $contents
     * @param  bool  $lock
     */
    public function put($path, $contents, $lock = false): bool|int
    {
        $destination = $this->destination ?? $path;

        $this->destination = null;

        return parent::put($destination, $contents, $lock);
    }

    public function setDestination(string $destination): void
    {
        $this->destination = $destination;
    }
}
