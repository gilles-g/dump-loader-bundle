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

namespace DumpLoader\Execution\Process;

use DumpLoader\Execution\CommandFailed;
use DumpLoader\Execution\LoadJobLauncher;
use DumpLoader\Execution\PhpBinaryNotFound;
use DumpLoader\LoadJob\LoadJobId;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

final readonly class DetachedProcessLauncher implements LoadJobLauncher
{
    private const COMMAND_NAME = 'dump-loader:run';
    private const LAUNCH_TIMEOUT_SECONDS = 10;

    public function __construct(
        private string $consolePath,
        private string $logDirectory,
        private ?string $phpBinary = null,
        private ExecutableFinder $executableFinder = new ExecutableFinder(),
        private PhpExecutableFinder $phpExecutableFinder = new PhpExecutableFinder(),
    ) {
    }

    public function launch(LoadJobId $id): void
    {
        $phpBinary = $this->phpBinary ?? $this->phpExecutableFinder->find();

        if (!\is_string($phpBinary) || '' === $phpBinary) {
            throw PhpBinaryNotFound::onThisMachine();
        }

        $setsid = $this->executableFinder->find('setsid');
        $env = [
            'DUMP_LOADER_PHP' => $phpBinary,
            'DUMP_LOADER_CONSOLE' => $this->consolePath,
            'DUMP_LOADER_JOB_ID' => $id->value,
            'DUMP_LOADER_LOG_FILE' => $this->logDirectory.'/'.$id->value.'.log',
        ];

        if (null !== $setsid) {
            $env['DUMP_LOADER_SETSID'] = $setsid;
        }

        $process = Process::fromShellCommandline($this->commandLine(null !== $setsid), null, $env);
        $process->setTimeout(self::LAUNCH_TIMEOUT_SECONDS);
        $process->run();

        if (0 !== $process->getExitCode()) {
            throw CommandFailed::withExitCode($process->getExitCode() ?? -1, OutputLines::fromBuffer($process->getErrorOutput())->values);
        }
    }

    private function commandLine(bool $withSetsid): string
    {
        return \sprintf(
            '%snohup "$DUMP_LOADER_PHP" "$DUMP_LOADER_CONSOLE" %s "$DUMP_LOADER_JOB_ID" </dev/null >>"$DUMP_LOADER_LOG_FILE" 2>&1 &',
            $withSetsid ? '"$DUMP_LOADER_SETSID" ' : '',
            self::COMMAND_NAME,
        );
    }
}
