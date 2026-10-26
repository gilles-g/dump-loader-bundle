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

namespace DumpLoader\Tests\Unit\Execution;

use DumpLoader\Execution\NoInput;
use DumpLoader\Execution\ShellCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ShellCommandTest extends TestCase
{
    #[Test]
    public function it_should_be_built_without_input_by_default(): void
    {
        $command = new ShellCommand(argv: ['mysql', '--user=app']);

        self::assertSame(['mysql', '--user=app'], $command->argv);
        self::assertSame([], $command->env);
        self::assertNull($command->cwd);
        self::assertInstanceOf(NoInput::class, $command->input);
    }

    #[Test]
    public function it_should_return_a_new_command_with_input_and_leave_the_original_without_input(): void
    {
        $command = new ShellCommand(argv: ['mysql'], env: ['MYSQL_PWD' => 'secret'], cwd: '/app');

        $piped = $command->withInput((static function (): iterable {
            yield 'INSERT INTO t VALUES (1);';
        })());

        self::assertNotSame($command, $piped);
        self::assertInstanceOf(NoInput::class, $command->input);
        self::assertSame(['INSERT INTO t VALUES (1);'], iterator_to_array($piped->input, false));
        self::assertSame(['mysql'], $piped->argv);
        self::assertSame(['MYSQL_PWD' => 'secret'], $piped->env);
        self::assertSame('/app', $piped->cwd);
    }

    #[Test]
    public function it_should_mask_environment_values_so_that_no_secret_reaches_the_log(): void
    {
        $command = new ShellCommand(
            argv: ['mysql', '--user=app'],
            env: ['MYSQL_PWD' => 'secret', 'MYSQL_HOST' => 'db.internal'],
        );

        $display = $command->display();

        self::assertSame('MYSQL_PWD=*** MYSQL_HOST=*** mysql --user=app', $display);
        self::assertStringNotContainsString('secret', $display);
    }

    #[Test]
    public function it_should_display_just_the_argv_when_there_is_no_environment(): void
    {
        $command = new ShellCommand(argv: ['php', 'bin/console', 'cache:clear']);

        self::assertSame('php bin/console cache:clear', $command->display());
    }
}
