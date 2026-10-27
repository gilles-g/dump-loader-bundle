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

use DumpLoader\Dump\GzippedSqlFile;
use DumpLoader\Execution\NoInput;
use DumpLoader\Execution\ShellCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GzippedSqlFileTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/dumps';

    #[Test]
    public function it_should_read_the_beginning_of_the_file_decompressed(): void
    {
        $file = new GzippedSqlFile(self::FIXTURES.'/simple.sql.gz', '/bin/bash', '/bin/gzip');

        self::assertStringStartsWith('-- MySQL dump', $file->header(64));
    }

    #[Test]
    public function it_should_pipe_the_decompressor_into_the_command(): void
    {
        $file = new GzippedSqlFile('/dumps/prod.sql.gz', '/bin/bash', '/bin/gzip');

        $command = $file->feed(new ShellCommand(argv: ['mysql', 'shop']));

        self::assertSame(['/bin/bash', '-c'], \array_slice($command->argv, 0, 2));
        self::assertSame(['/bin/bash', '/bin/gzip', '/dumps/prod.sql.gz', 'mysql', 'shop'], \array_slice($command->argv, 3));
    }

    #[Test]
    public function it_should_make_the_pipeline_fail_when_the_decompressor_fails(): void
    {
        $file = new GzippedSqlFile('/dumps/prod.sql.gz', '/bin/bash', '/bin/gzip');

        $command = $file->feed(new ShellCommand(argv: ['mysql']));

        self::assertStringContainsString('set -o pipefail', $command->argv[2]);
    }

    #[Test]
    public function it_should_forward_a_termination_to_the_pipeline(): void
    {
        $file = new GzippedSqlFile('/dumps/prod.sql.gz', '/bin/bash', '/bin/gzip');

        $command = $file->feed(new ShellCommand(argv: ['mysql']));

        self::assertStringContainsString('trap \'kill -TERM "$pipeline" 2>/dev/null\' TERM INT', $command->argv[2]);
    }

    #[Test]
    public function it_should_leave_nothing_for_php_to_push(): void
    {
        $file = new GzippedSqlFile('/dumps/prod.sql.gz', '/bin/bash', '/bin/gzip');

        $command = $file->feed(new ShellCommand(argv: ['mysql'], env: ['TZ' => 'UTC'], cwd: '/srv'));

        self::assertInstanceOf(NoInput::class, $command->input);
        self::assertSame(['TZ' => 'UTC'], $command->env);
        self::assertSame('/srv', $command->cwd);
    }
}
