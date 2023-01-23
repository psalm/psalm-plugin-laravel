<?php

namespace Psalm\LaravelPlugin\Fakes;

use Illuminate\Filesystem\Filesystem;

final class FakeFilesystem extends Filesystem
{
    /** @var ?string */
    private $destination = '';

    /**
     * Write the contents of a file.
     *
     * @param  string  $path
     * @param  string  $contents
     * @param  bool  $lock
     * @return bool|int
     */
    public function put($path, $contents, $lock = false)
    {
        $destination = $this->destination ?: $path;

        $this->destination = null;

        return parent::put($destination, $contents, $lock);
    }

    /**
     * @return void
     */
    public function setDestination(string $destination)
    {
        $this->destination = $destination;
    }
}
