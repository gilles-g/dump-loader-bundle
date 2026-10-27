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

namespace DumpLoader\Tests\Integration\LoadJob\File;

use DumpLoader\LoadJob\File\FlockJobLock;
use DumpLoader\LoadJob\File\JobDirectoryNotWritable;
use DumpLoader\LoadJob\LoadJobId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class FlockJobLockTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/dump-loader-lock-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        foreach (array_diff(scandir($this->directory) ?: [], ['.', '..']) as $entry) {
            unlink($this->directory.'/'.$entry);
        }

        rmdir($this->directory);
    }

    #[Test]
    public function it_should_be_free_until_it_is_acquired(): void
    {
        $lock = new FlockJobLock($this->directory);

        self::assertTrue($lock->isFree($this->anId()));
    }

    #[Test]
    public function it_should_acquire_the_lock(): void
    {
        $lock = new FlockJobLock($this->directory);

        self::assertTrue($lock->acquire($this->anId()));
        self::assertFalse($lock->isFree($this->anId()));
    }

    #[Test]
    public function it_should_acquire_the_same_lock_twice(): void
    {
        $lock = new FlockJobLock($this->directory);
        $lock->acquire($this->anId());

        self::assertTrue($lock->acquire($this->anId()));
    }

    #[Test]
    public function it_should_refuse_a_lock_held_by_another_process(): void
    {
        $holder = $this->processHoldingTheLock();
        $lock = new FlockJobLock($this->directory);

        try {
            self::assertFalse($lock->isFree($this->anId()));
            self::assertFalse($lock->acquire($this->anId()));
        } finally {
            $holder->signal(9);
            $holder->wait();
        }
    }

    #[Test]
    public function it_should_die_with_the_process_holding_it(): void
    {
        $holder = $this->processHoldingTheLock();
        $holder->signal(9);
        $holder->wait();

        $lock = new FlockJobLock($this->directory);

        self::assertTrue($lock->isFree($this->anId()));
        self::assertTrue($lock->acquire($this->anId()));
    }

    #[Test]
    public function it_should_give_each_job_its_own_lock(): void
    {
        $lock = new FlockJobLock($this->directory);
        $lock->acquire($this->anId());

        self::assertTrue($lock->isFree(LoadJobId::fromString('01J8Z9P0000000000000000002')));
    }

    #[Test]
    public function it_should_let_another_holder_take_a_released_lock(): void
    {
        $lock = new FlockJobLock($this->directory);
        $lock->acquire($this->anId());

        self::assertFalse((new FlockJobLock($this->directory))->acquire($this->anId()));

        $lock->release($this->anId());

        self::assertTrue($lock->isFree($this->anId()));
        self::assertTrue((new FlockJobLock($this->directory))->acquire($this->anId()));
    }

    #[Test]
    public function it_should_ignore_the_release_of_a_lock_it_does_not_hold(): void
    {
        $lock = new FlockJobLock($this->directory);

        $lock->release($this->anId());

        self::assertTrue($lock->isFree($this->anId()));
    }

    private function processHoldingTheLock(): Process
    {
        mkdir($this->directory, 0o775, true);
        $path = $this->directory.'/'.$this->anId()->value.'.lock';

        $process = new Process([
            \PHP_BINARY,
            '-r',
            \sprintf('$f = fopen(%s, "c"); flock($f, LOCK_EX); echo "held"; sleep(30);', var_export($path, true)),
        ]);
        $process->start();

        $deadline = microtime(true) + 10;
        while ('held' !== $process->getOutput() && microtime(true) < $deadline) {
            usleep(10_000);
        }

        self::assertSame('held', $process->getOutput());

        return $process;
    }

    #[Test]
    public function it_should_refuse_to_lock_inside_a_directory_it_cannot_create(): void
    {
        $this->expectException(JobDirectoryNotWritable::class);

        (new FlockJobLock('/proc/nowhere/dump-loader'))->acquire($this->anId());
    }

    #[Test]
    public function it_should_refuse_to_lock_a_file_it_cannot_open(): void
    {
        mkdir($this->directory, 0o775, true);
        touch($this->directory.'/'.$this->anId()->value.'.lock');
        chmod($this->directory.'/'.$this->anId()->value.'.lock', 0o000);

        try {
            $this->expectException(JobDirectoryNotWritable::class);

            (new FlockJobLock($this->directory))->acquire($this->anId());
        } finally {
            chmod($this->directory.'/'.$this->anId()->value.'.lock', 0o644);
        }
    }

    #[Test]
    public function it_should_hold_a_lock_it_cannot_open_to_be_taken(): void
    {
        mkdir($this->directory, 0o775, true);
        touch($this->directory.'/'.$this->anId()->value.'.lock');
        chmod($this->directory.'/'.$this->anId()->value.'.lock', 0o000);

        try {
            self::assertFalse((new FlockJobLock($this->directory))->isFree($this->anId()));
        } finally {
            chmod($this->directory.'/'.$this->anId()->value.'.lock', 0o644);
        }
    }

    private function anId(): LoadJobId
    {
        return LoadJobId::fromString('01J8Z9P0000000000000000001');
    }
}
