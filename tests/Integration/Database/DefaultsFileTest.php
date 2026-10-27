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

namespace DumpLoader\Tests\Integration\Database;

use DumpLoader\Database\Mysql\DefaultsFile;
use DumpLoader\Database\Mysql\DefaultsFileNotWritable;
use DumpLoader\Database\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DefaultsFileTest extends TestCase
{
    private string $varDir;

    protected function setUp(): void
    {
        $this->varDir = sys_get_temp_dir().'/dump-loader-defaults-file-'.bin2hex(random_bytes(6));
        mkdir($this->varDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->varDir.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->varDir);
    }

    #[Test]
    public function it_should_write_the_file_in_the_var_dir(): void
    {
        $path = new DefaultsFile($this->varDir)->withCredentials($this->database(), static fn (string $path): string => $path);

        self::assertStringStartsWith($this->varDir.'/'.DefaultsFile::PREFIX, $path);
    }

    #[Test]
    public function it_should_keep_the_file_private_to_the_php_user(): void
    {
        $mode = new DefaultsFile($this->varDir)->withCredentials($this->database(), static fn (string $path): int => fileperms($path) & 0o777);

        self::assertSame(0o600, $mode);
    }

    #[Test]
    public function it_should_write_the_credentials_of_the_target_database(): void
    {
        $content = new DefaultsFile($this->varDir)->withCredentials($this->database(), $this->contentOf(...));

        self::assertStringContainsString("[client]\nhost = \"db\"\nport = 3307\nuser = \"app\"\n", $content);
        self::assertStringContainsString('password = "s3\"cr\\\\#t"', $content);
        self::assertStringContainsString('default-character-set = "utf8mb4"', $content);
    }

    #[Test]
    public function it_should_turn_tls_off_for_the_mariadb_client_by_default(): void
    {
        $content = new DefaultsFile($this->varDir)->withCredentials($this->database(), $this->contentOf(...));

        self::assertStringContainsString("\n[client-mariadb]\nskip-ssl\n", $content);
    }

    #[Test]
    public function it_should_let_the_mariadb_client_use_tls_when_asked(): void
    {
        $content = new DefaultsFile($this->varDir, ssl: true)->withCredentials($this->database(), $this->contentOf(...));

        self::assertStringContainsString("\n[client-mariadb]\nssl\n", $content);
        self::assertStringNotContainsString('skip-ssl', $content);
    }

    #[Test]
    public function it_should_delete_the_file_once_used(): void
    {
        new DefaultsFile($this->varDir)->withCredentials($this->database(), static fn (string $path): bool => true);

        self::assertSame([], glob($this->varDir.'/'.DefaultsFile::PREFIX.'*'));
    }

    #[Test]
    public function it_should_delete_the_file_when_its_use_fails(): void
    {
        $this->expectExceptionMessage('client crashed');

        try {
            new DefaultsFile($this->varDir)->withCredentials($this->database(), static function (string $path): never {
                throw new \RuntimeException('client crashed');
            });
        } finally {
            self::assertSame([], glob($this->varDir.'/'.DefaultsFile::PREFIX.'*'));
        }
    }

    #[DataProvider('credentialsWithALineBreak')]
    #[Test]
    public function it_should_refuse_a_credential_holding_a_line_break_without_writing_anything(TargetDatabase $database, string $field): void
    {
        try {
            new DefaultsFile($this->varDir)->withCredentials($database, static fn (string $path): bool => true);
            self::fail('The line break should have been refused.');
        } catch (DefaultsFileNotWritable $exception) {
            self::assertStringContainsString('the '.$field.' of the target database DSN', $exception->getMessage());
            self::assertStringNotContainsString('s3cr', $exception->getMessage());
        }

        self::assertSame([], glob($this->varDir.'/*'));
    }

    /**
     * @return iterable<string, array{TargetDatabase, string}>
     */
    public static function credentialsWithALineBreak(): iterable
    {
        yield 'password' => [new TargetDatabase('db', 3307, 'shop', 'app', "s3cr\n#t", 'utf8mb4'), 'password'];
        yield 'user' => [new TargetDatabase('db', 3307, 'shop', "app\rroot", 's3cr#t', 'utf8mb4'), 'user'];
        yield 'host' => [new TargetDatabase("db\n", 3307, 'shop', 'app', 's3cr#t', 'utf8mb4'), 'host'];
        yield 'charset' => [new TargetDatabase('db', 3307, 'shop', 'app', 's3cr#t', "utf8mb4\nskip-ssl"), 'charset'];
    }

    #[Test]
    public function it_should_fail_when_the_var_dir_cannot_be_created(): void
    {
        $this->expectException(DefaultsFileNotWritable::class);

        new DefaultsFile('/proc/nowhere/dump-loader')->withCredentials($this->database(), static fn (string $path): bool => true);
    }

    #[Test]
    public function it_should_never_fall_back_to_the_system_temporary_directory(): void
    {
        $before = glob(sys_get_temp_dir().'/'.DefaultsFile::PREFIX.'*') ?: [];

        try {
            new DefaultsFile('/proc')->withCredentials($this->database(), static fn (string $path): bool => true);
            self::fail('Writing into an unwritable var_dir should have failed.');
        } catch (DefaultsFileNotWritable $exception) {
            self::assertStringContainsString('"/proc"', $exception->getMessage());
        }

        self::assertSame($before, glob(sys_get_temp_dir().'/'.DefaultsFile::PREFIX.'*') ?: []);
    }

    private function contentOf(string $path): string
    {
        $content = file_get_contents($path);
        self::assertIsString($content);

        return $content;
    }

    private function database(): TargetDatabase
    {
        return new TargetDatabase(host: 'db', port: 3307, name: 'shop', user: 'app', password: 's3"cr\#t', charset: 'utf8mb4');
    }
}
