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

namespace DumpLoader\Tests\Integration\Dump;

use DumpLoader\Dump\Dump;
use DumpLoader\Dump\Dumps;
use DumpLoader\Dump\DumpSource;
use DumpLoader\Dump\FileSize;
use DumpLoader\Dump\GzippedSqlFile;
use DumpLoader\Dump\LocalDumpFiles;
use DumpLoader\Dump\PlainSqlFile;
use DumpLoader\Dump\SqlInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;

final class LocalDumpFilesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/dump-loader-local-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/dumps', 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/dumps/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory.'/dumps');
        rmdir($this->directory);
    }

    #[Test]
    public function it_should_delegate_the_listing(): void
    {
        $dumps = new Dumps(self::dump('dumps/prod.sql'));
        $inner = $this->createMock(DumpSource::class);
        $inner->expects(self::once())->method('list')->willReturn($dumps);

        self::assertSame($dumps, $this->source($inner)->list());
    }

    #[Test]
    public function it_should_delegate_the_lookup(): void
    {
        $dump = self::dump('dumps/prod.sql');
        $inner = $this->createMock(DumpSource::class);
        $inner->expects(self::once())->method('find')->with('dumps/prod.sql')->willReturn($dump);

        self::assertSame($dump, $this->source($inner)->find('dumps/prod.sql'));
    }

    #[Test]
    public function it_should_hand_a_plain_file_over_to_the_shell(): void
    {
        $this->write('dumps/prod.sql', 'SELECT 1;');

        $input = $this->source($this->neverOpening())->open(self::dump('dumps/prod.sql'));

        self::assertInstanceOf(PlainSqlFile::class, $input);
    }

    #[Test]
    public function it_should_hand_a_gzipped_file_over_to_the_shell(): void
    {
        $this->write('dumps/prod.sql.gz', (string) gzencode('SELECT 1;'));

        $input = $this->source($this->neverOpening())->open(self::dump('dumps/prod.sql.gz'));

        self::assertInstanceOf(GzippedSqlFile::class, $input);
    }

    #[Test]
    public function it_should_fall_back_on_the_stream_when_the_file_is_not_on_this_disk(): void
    {
        $delegated = $this->createStub(SqlInput::class);

        self::assertSame($delegated, $this->source($this->opening($delegated))->open(self::dump('dumps/prod.sql')));
    }

    #[Test]
    public function it_should_fall_back_on_the_stream_when_the_path_is_a_directory(): void
    {
        $delegated = $this->createStub(SqlInput::class);
        mkdir($this->directory.'/dumps/nested.sql');

        self::assertSame($delegated, $this->source($this->opening($delegated))->open(self::dump('dumps/nested.sql')));

        rmdir($this->directory.'/dumps/nested.sql');
    }

    #[Test]
    public function it_should_fall_back_on_the_stream_when_no_shell_is_available(): void
    {
        $this->write('dumps/prod.sql', 'SELECT 1;');
        $delegated = $this->createStub(SqlInput::class);

        $source = new LocalDumpFiles($this->opening($delegated), $this->directory, $this->findingNothing());

        self::assertSame($delegated, $source->open(self::dump('dumps/prod.sql')));
    }

    #[Test]
    public function it_should_fall_back_on_the_stream_when_no_decompressor_is_available(): void
    {
        $this->write('dumps/prod.sql.gz', (string) gzencode('SELECT 1;'));
        $delegated = $this->createStub(SqlInput::class);

        $source = new LocalDumpFiles($this->opening($delegated), $this->directory, $this->findingNothing());

        self::assertSame($delegated, $source->open(self::dump('dumps/prod.sql.gz')));
    }

    private function source(DumpSource $inner): LocalDumpFiles
    {
        return new LocalDumpFiles($inner, $this->directory);
    }

    private function neverOpening(): DumpSource
    {
        $inner = $this->createMock(DumpSource::class);
        $inner->expects(self::never())->method('open');

        return $inner;
    }

    private function opening(SqlInput $input): DumpSource
    {
        $inner = $this->createMock(DumpSource::class);
        $inner->expects(self::once())->method('open')->willReturn($input);

        return $inner;
    }

    private function findingNothing(): ExecutableFinder
    {
        $executableFinder = $this->createStub(ExecutableFinder::class);
        $executableFinder->method('find')->willReturn(null);

        return $executableFinder;
    }

    private function write(string $path, string $contents): void
    {
        file_put_contents($this->directory.'/'.$path, $contents);
    }

    private static function dump(string $path): Dump
    {
        return Dump::fromPath($path, FileSize::fromBytes(9), new \DateTimeImmutable('2026-09-18 08:00:00'));
    }
}
