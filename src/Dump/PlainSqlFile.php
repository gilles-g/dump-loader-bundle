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

namespace DumpLoader\Dump;

use DumpLoader\Execution\ShellCommand;

final readonly class PlainSqlFile implements SqlInput
{
    private const SCRIPT = <<<'SH'
        dump=$1
        shift
        exec "$@" < "$dump"
        SH;

    public function __construct(
        private string $path,
        private string $shell,
    ) {
    }

    public function header(int $window): string
    {
        return DumpFormat::Sql->header($this->path, $window);
    }

    public function feed(ShellCommand $command): ShellCommand
    {
        return new ShellCommand(
            argv: [$this->shell, '-c', self::SCRIPT, $this->shell, $this->path, ...$command->argv],
            env: $command->env,
            cwd: $command->cwd,
        );
    }
}
