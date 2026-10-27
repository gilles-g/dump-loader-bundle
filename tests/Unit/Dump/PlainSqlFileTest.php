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

namespace DumpLoader\Tests\Unit\Dump;

use DumpLoader\Dump\PlainSqlFile;
use DumpLoader\Execution\NoInput;
use DumpLoader\Execution\ShellCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlainSqlFileTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../Fixtures/dumps';

    #[Test]
    public function it_should_read_the_beginning_of_the_file(): void
    {
        $file = new PlainSqlFile(self::FIXTURES.'/simple.sql', '/bin/sh');

        self::assertStringStartsWith('-- MySQL dump', $file->header(64));
    }

    #[Test]
    public function it_should_hand_the_file_to_the_shell_as_standard_input(): void
    {
        $file = new PlainSqlFile('/dumps/prod.sql', '/bin/sh');

        $command = $file->feed(new ShellCommand(argv: ['mysql', '--one-database', 'shop']));

        self::assertSame(
            ['/bin/sh', '-c', "dump=\$1\nshift\nexec \"\$@\" < \"\$dump\"", '/bin/sh', '/dumps/prod.sql', 'mysql', '--one-database', 'shop'],
            $command->argv,
        );
    }

    #[Test]
    public function it_should_leave_nothing_for_php_to_push(): void
    {
        $file = new PlainSqlFile('/dumps/prod.sql', '/bin/sh');

        $command = $file->feed(new ShellCommand(argv: ['mysql']));

        self::assertInstanceOf(NoInput::class, $command->input);
    }

    #[Test]
    public function it_should_keep_the_environment_and_the_working_directory_of_the_command(): void
    {
        $file = new PlainSqlFile('/dumps/prod.sql', '/bin/sh');

        $command = $file->feed(new ShellCommand(argv: ['mysql'], env: ['TZ' => 'UTC'], cwd: '/srv'));

        self::assertSame(['TZ' => 'UTC'], $command->env);
        self::assertSame('/srv', $command->cwd);
    }
}
