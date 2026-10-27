<?php

declare(strict_types=1);

/*
 * This file is part of the gilles-g/dump-loader-bundle package.
 *
 * (c) gilles-g <ggauthier@spiriit.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DumpLoader\LoadJob\File;

use DumpLoader\LoadJob\AbortSignal;
use DumpLoader\LoadJob\LoadJobId;

final readonly class FileAbortSignal implements AbortSignal
{
    public function __construct(
        private string $directory,
    ) {
    }

    public function request(LoadJobId $id): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            throw JobDirectoryNotWritable::at($this->directory);
        }

        if (false === @file_put_contents($this->pathFor($id), '')) {
            throw JobDirectoryNotWritable::at($this->directory);
        }
    }

    public function isRaised(LoadJobId $id): bool
    {
        clearstatcache(true, $this->pathFor($id));

        return is_file($this->pathFor($id));
    }

    public function clear(LoadJobId $id): void
    {
        if (is_file($this->pathFor($id))) {
            @unlink($this->pathFor($id));
        }

        clearstatcache(true, $this->pathFor($id));
    }

    private function pathFor(LoadJobId $id): string
    {
        return $this->directory.'/'.$id->value.'.abort';
    }
}
