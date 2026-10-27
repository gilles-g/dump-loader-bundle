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

final readonly class GzippedSqlFile implements SqlInput
{
    private const string SCRIPT = <<<'SH'
        set -o pipefail
        decompressor=$1
        dump=$2
        shift 2
        pipeline=$$
        trap 'kill -TERM "$pipeline" 2>/dev/null' TERM INT
        "$decompressor" -dc -- "$dump" | "$@" &
        pipeline=$!
        wait "$pipeline"
        SH;

    public function __construct(
        private string $path,
        private string $shell,
        private string $decompressor,
    ) {
    }

    public function header(int $window): string
    {
        return DumpFormat::GzippedSql->header($this->path, $window);
    }

    public function feed(ShellCommand $command): ShellCommand
    {
        return new ShellCommand(
            argv: [$this->shell, '-c', self::SCRIPT, $this->shell, $this->decompressor, $this->path, ...$command->argv],
            env: $command->env,
            cwd: $command->cwd,
        );
    }
}
