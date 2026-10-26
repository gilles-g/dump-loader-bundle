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

namespace DumpLoader\Requirements;

use DumpLoader\Database\InvalidDsn;
use DumpLoader\Database\Mysql\MysqlBinary;
use DumpLoader\Database\Mysql\MysqlBinaryNotFound;
use DumpLoader\Database\TargetDatabaseDsn;
use DumpLoader\Dump\DumpSource;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;

use function Symfony\Component\String\u;

final readonly class CheckRequirements
{
    public function __construct(
        private DumpSource $dumpSource,
        private TargetDatabaseDsn $targetDatabaseDsn,
        private string $varDir,
        private string $console,
        private MysqlBinary $mysqlBinary,
        private ?string $phpBinary = null,
        private ExecutableFinder $executableFinder = new ExecutableFinder(),
        private PhpExecutableFinder $phpExecutableFinder = new PhpExecutableFinder(),
        private ?string $disabledFunctions = null,
    ) {
    }

    public function __invoke(): Requirements
    {
        return new Requirements(
            $this->mysqlClient(),
            $this->php(),
            $this->processOpen(),
            $this->writableVarDir(),
            $this->console(),
            $this->detachment(),
            $this->targetDatabase(),
            $this->dumps(),
        );
    }

    private function targetDatabase(): Requirement
    {
        try {
            $database = $this->targetDatabaseDsn->targetDatabase();
        } catch (InvalidDsn $exception) {
            return Requirement::missing('requirements.target_database', $exception->getMessage());
        }

        return Requirement::satisfied('requirements.target_database', $database->displayName());
    }

    private function mysqlClient(): Requirement
    {
        try {
            $path = $this->mysqlBinary->locate();
        } catch (MysqlBinaryNotFound $exception) {
            return Requirement::missing('requirements.mysql_binary', $exception->getMessage());
        }

        return Requirement::satisfied('requirements.mysql_binary', $path);
    }

    private function php(): Requirement
    {
        $path = $this->phpBinary ?? $this->phpExecutableFinder->find();

        if (false === $path) {
            return Requirement::missing('requirements.php_binary', 'No PHP binary could be located; set "dump_loader.php_binary".');
        }

        return Requirement::satisfied('requirements.php_binary', $path);
    }

    private function processOpen(): Requirement
    {
        $disabled = u($this->disabledFunctions ?? (string) \ini_get('disable_functions'))->replace(' ', '')->split(',');

        foreach ($disabled as $function) {
            if ('proc_open' === $function->toString()) {
                return Requirement::missing('requirements.proc_open', 'proc_open() is listed in disable_functions.');
            }
        }

        return Requirement::satisfied('requirements.proc_open');
    }

    private function writableVarDir(): Requirement
    {
        if (is_dir($this->varDir)) {
            return is_writable($this->varDir)
                ? Requirement::satisfied('requirements.var_dir', $this->varDir)
                : Requirement::missing('requirements.var_dir', \sprintf('"%s" is not writable.', $this->varDir));
        }

        $parent = \dirname($this->varDir);

        return is_dir($parent) && is_writable($parent)
            ? Requirement::satisfied('requirements.var_dir', \sprintf('"%s" will be created.', $this->varDir))
            : Requirement::missing('requirements.var_dir', \sprintf('"%s" cannot be created.', $this->varDir));
    }

    private function console(): Requirement
    {
        if (!is_file($this->console)) {
            return Requirement::missing('requirements.console', \sprintf('"%s" does not exist.', $this->console));
        }

        return Requirement::satisfied('requirements.console', $this->console);
    }

    private function detachment(): Requirement
    {
        $setsid = $this->executableFinder->find('setsid');

        if (null === $setsid) {
            return Requirement::satisfied(
                'requirements.setsid',
                'setsid is missing; jobs will be detached with nohup only and die with the container.',
            );
        }

        return Requirement::satisfied('requirements.setsid', $setsid);
    }

    private function dumps(): Requirement
    {
        try {
            $count = \count($this->dumpSource->list());
        } catch (\Throwable $exception) {
            return Requirement::missing('requirements.dumps', $exception->getMessage());
        }

        return Requirement::satisfied('requirements.dumps', \sprintf('%d dump(s) available.', $count));
    }
}
