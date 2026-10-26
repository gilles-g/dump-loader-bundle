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

namespace DumpLoader\Database\Mysql;

use DumpLoader\Database\DatabaseClient;
use DumpLoader\Database\TargetDatabase;
use DumpLoader\Dump\SqlInput;
use DumpLoader\Execution\ProcessRunner;
use DumpLoader\Execution\ShellCommand;
use DumpLoader\LoadJob\LogTag;
use DumpLoader\Step\StepTracker;

final readonly class MysqlClient implements DatabaseClient
{
    private const MAX_ALLOWED_PACKET = '1G';

    private const DROP_EVERYTHING = <<<'SQL'
        SET FOREIGN_KEY_CHECKS = 0;
        SET SESSION group_concat_max_len = 1073741824;
        SELECT COALESCE(GROUP_CONCAT(CONCAT('`', REPLACE(TABLE_NAME, '`', '``'), '`')), '') INTO @views
            FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'VIEW';
        SET @drop = IF('' = @views, 'DO 0', CONCAT('DROP VIEW IF EXISTS ', @views));
        PREPARE drop_views FROM @drop;
        EXECUTE drop_views;
        DEALLOCATE PREPARE drop_views;
        SELECT COALESCE(GROUP_CONCAT(CONCAT('`', REPLACE(TABLE_NAME, '`', '``'), '`')), '') INTO @tables
            FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE';
        SET @drop = IF('' = @tables, 'DO 0', CONCAT('DROP TABLE IF EXISTS ', @tables));
        PREPARE drop_tables FROM @drop;
        EXECUTE drop_tables;
        DEALLOCATE PREPARE drop_tables;
        SET FOREIGN_KEY_CHECKS = 1;
        SQL;

    public function __construct(
        private ProcessRunner $processRunner,
        private DefaultsFile $defaultsFile,
        private MysqlBinary $mysqlBinary = new MysqlBinary(),
        private MysqlRestorePolicy $mysqlRestorePolicy = new MysqlRestorePolicy(),
    ) {
    }

    public function restore(TargetDatabase $database, SqlInput $input, StepTracker $tracker): void
    {
        $binary = $this->mysqlBinary->locate();
        $this->mysqlRestorePolicy->ensureRestorable($input);

        $this->defaultsFile->withCredentials($database, fn (string $defaultsFile) => $this->run(
            $input->feed(new ShellCommand(
                argv: [
                    $binary,
                    '--defaults-extra-file='.$defaultsFile,
                    '--one-database',
                    '--max-allowed-packet='.self::MAX_ALLOWED_PACKET,
                    $database->name,
                ],
            )),
            $tracker,
        ));
    }

    public function dropAllTables(TargetDatabase $database, StepTracker $tracker): void
    {
        $binary = $this->mysqlBinary->locate();

        $this->defaultsFile->withCredentials($database, fn (string $defaultsFile) => $this->run(
            new ShellCommand(
                argv: [
                    $binary,
                    '--defaults-extra-file='.$defaultsFile,
                    $database->name,
                ],
                input: [self::DROP_EVERYTHING],
            ),
            $tracker,
        ));
    }

    private function run(ShellCommand $command, StepTracker $tracker): void
    {
        $tracker->log(LogTag::Run, $command->display());

        $this->processRunner->run($command, $tracker)->ensureSuccess();
    }
}
