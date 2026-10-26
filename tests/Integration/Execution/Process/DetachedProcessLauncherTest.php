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

namespace DumpLoader\Tests\Integration\Execution\Process;

use DumpLoader\Execution\PhpBinaryNotFound;
use DumpLoader\Execution\Process\DetachedProcessLauncher;
use DumpLoader\LoadJob\LoadJobId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;

final class DetachedProcessLauncherTest extends TestCase
{
    private string $logDirectory;

    protected function setUp(): void
    {
        $this->logDirectory = sys_get_temp_dir().'/dump-loader-launcher-'.bin2hex(random_bytes(6));
        mkdir($this->logDirectory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logDirectory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->logDirectory);
    }

    #[Test]
    public function it_should_run_the_console_command_detached_and_redirect_its_output_to_the_job_log(): void
    {
        $id = LoadJobId::generate();

        $this->launcher()->launch($id);

        self::assertSame('dump-loader:run '.$id->value, $this->awaitLog($id));
    }

    #[Test]
    public function it_should_still_launch_when_setsid_is_unavailable(): void
    {
        $executableFinder = $this->createMock(ExecutableFinder::class);
        $executableFinder->method('find')->willReturn(null);

        $id = LoadJobId::generate();

        $this->launcher(executableFinder: $executableFinder)->launch($id);

        self::assertSame('dump-loader:run '.$id->value, $this->awaitLog($id));
    }

    #[Test]
    public function it_should_fail_when_no_php_binary_can_be_found(): void
    {
        $finder = $this->createMock(PhpExecutableFinder::class);
        $finder->method('find')->willReturn(false);

        $this->expectException(PhpBinaryNotFound::class);

        $this->launcher(phpBinary: null, phpExecutableFinder: $finder)->launch(LoadJobId::generate());
    }

    private function launcher(?string $phpBinary = null, ?PhpExecutableFinder $phpExecutableFinder = null, ?ExecutableFinder $executableFinder = null): DetachedProcessLauncher
    {
        return new DetachedProcessLauncher(
            consolePath: __DIR__.'/Fixtures/console.php',
            logDirectory: $this->logDirectory,
            phpBinary: $phpBinary,
            executableFinder: $executableFinder ?? new ExecutableFinder(),
            phpExecutableFinder: $phpExecutableFinder ?? new PhpExecutableFinder(),
        );
    }

    private function awaitLog(LoadJobId $id): string
    {
        $path = $this->logDirectory.'/'.$id->value.'.log';

        for ($attempt = 0; $attempt < 100; ++$attempt) {
            if (is_file($path) && '' !== trim((string) file_get_contents($path))) {
                return trim((string) file_get_contents($path));
            }
            usleep(100_000);
        }

        return '';
    }
}
