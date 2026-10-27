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

use DumpLoader\LoadJob\File\FileAbortSignal;
use DumpLoader\LoadJob\File\JobDirectoryNotWritable;
use DumpLoader\LoadJob\LoadJobId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileAbortSignalTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/dump-loader-abort-'.bin2hex(random_bytes(6));
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
    public function it_should_raise_no_signal_by_default(): void
    {
        $signal = new FileAbortSignal($this->directory);

        self::assertFalse($signal->isRaised($this->anId()));
    }

    #[Test]
    public function it_should_raise_the_signal(): void
    {
        $signal = new FileAbortSignal($this->directory);

        $signal->request($this->anId());

        self::assertTrue($signal->isRaised($this->anId()));
        self::assertFileExists($this->directory.'/01J8Z9P0000000000000000001.abort');
    }

    #[Test]
    public function it_should_raise_the_signal_of_a_single_job(): void
    {
        $signal = new FileAbortSignal($this->directory);

        $signal->request($this->anId());

        self::assertFalse($signal->isRaised(LoadJobId::fromString('01J8Z9P0000000000000000002')));
    }

    #[Test]
    public function it_should_clear_the_signal(): void
    {
        $signal = new FileAbortSignal($this->directory);
        $signal->request($this->anId());

        $signal->clear($this->anId());

        self::assertFalse($signal->isRaised($this->anId()));
    }

    #[Test]
    public function it_should_clear_a_signal_that_was_never_raised(): void
    {
        $signal = new FileAbortSignal($this->directory);

        $signal->clear($this->anId());

        self::assertFalse($signal->isRaised($this->anId()));
    }

    #[Test]
    public function it_should_refuse_to_raise_a_signal_in_a_directory_it_cannot_create(): void
    {
        $this->expectException(JobDirectoryNotWritable::class);

        (new FileAbortSignal('/proc/nowhere/dump-loader'))->request($this->anId());
    }

    #[Test]
    public function it_should_refuse_to_raise_a_signal_it_cannot_write(): void
    {
        mkdir($this->directory, 0o500, true);

        try {
            $this->expectException(JobDirectoryNotWritable::class);

            (new FileAbortSignal($this->directory))->request($this->anId());
        } finally {
            chmod($this->directory, 0o775);
        }
    }

    private function anId(): LoadJobId
    {
        return LoadJobId::fromString('01J8Z9P0000000000000000001');
    }
}
