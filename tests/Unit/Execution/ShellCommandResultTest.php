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

use DumpLoader\Execution\CommandFailed;
use DumpLoader\Execution\ShellCommandResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ShellCommandResultTest extends TestCase
{
    #[Test]
    public function it_should_expose_the_exit_code_and_the_last_error_lines(): void
    {
        $result = new ShellCommandResult(exitCode: 1, errorOutput: ['ERROR 1045 (28000): Access denied']);

        self::assertSame(1, $result->exitCode);
        self::assertSame(['ERROR 1045 (28000): Access denied'], $result->errorOutput);
    }

    #[Test]
    public function it_should_let_a_zero_exit_code_through(): void
    {
        $this->expectNotToPerformAssertions();

        (new ShellCommandResult(exitCode: 0, errorOutput: []))->ensureSuccess();
    }

    #[Test]
    public function it_should_report_the_exit_code_and_the_error_output(): void
    {
        $result = new ShellCommandResult(
            exitCode: 137,
            errorOutput: ['ERROR 1045 (28000): Access denied for user', 'ERROR at line 12'],
        );

        try {
            $result->ensureSuccess();
            self::fail('a non zero exit code must be reported');
        } catch (CommandFailed $exception) {
            self::assertStringContainsString('137', $exception->getMessage());
            self::assertStringContainsString('ERROR at line 12', $exception->getMessage());
        }
    }
}
