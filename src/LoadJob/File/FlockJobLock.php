<?php

declare(strict_types=1);

/*
 * This file is part of the gilles-g/dump-loader package.
 *
 * (c) gilles-g <ggauthier@spiriit.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DumpLoader\LoadJob\File;

use DumpLoader\LoadJob\JobLock;
use DumpLoader\LoadJob\LoadJobId;

final class FlockJobLock implements JobLock
{
    /**
     * @var array<string, resource>
     */
    private array $handles = [];

    public function __construct(
        private readonly string $directory,
    ) {
    }

    public function acquire(LoadJobId $id): bool
    {
        if (isset($this->handles[$id->value])) {
            return true;
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            throw JobDirectoryNotWritable::at($this->directory);
        }

        $handle = @fopen($this->pathFor($id), 'c');

        if (false === $handle) {
            throw JobDirectoryNotWritable::at($this->directory);
        }

        if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->handles[$id->value] = $handle;

        return true;
    }

    public function isFree(LoadJobId $id): bool
    {
        if (isset($this->handles[$id->value])) {
            return false;
        }

        if (!is_file($this->pathFor($id))) {
            return true;
        }

        $handle = @fopen($this->pathFor($id), 'c');

        if (false === $handle) {
            return false;
        }

        $free = flock($handle, \LOCK_EX | \LOCK_NB);

        if ($free) {
            flock($handle, \LOCK_UN);
        }

        fclose($handle);

        return $free;
    }

    public function release(LoadJobId $id): void
    {
        if (!isset($this->handles[$id->value])) {
            return;
        }

        flock($this->handles[$id->value], \LOCK_UN);
        fclose($this->handles[$id->value]);
        unset($this->handles[$id->value]);
    }

    private function pathFor(LoadJobId $id): string
    {
        return $this->directory.'/'.$id->value.'.lock';
    }
}
