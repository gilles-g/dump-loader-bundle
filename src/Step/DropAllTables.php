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

namespace DumpLoader\Step;

use DumpLoader\Database\DatabaseClient;
use DumpLoader\Database\RestorePolicy;
use DumpLoader\Database\TargetDatabaseDsn;
use DumpLoader\Dump\DumpSource;
use DumpLoader\Execution\CommandFailed;
use DumpLoader\LoadJob\LogTag;

final readonly class DropAllTables implements Step
{
    public const string NAME = 'drop_all_tables';

    public function __construct(
        private DumpSource $dumpSource,
        private RestorePolicy $restorePolicy,
        private DatabaseClient $database,
        private TargetDatabaseDsn $targetDatabaseDsn,
    ) {
    }

    public function definition(): StepDefinition
    {
        return new StepDefinition(
            name: self::NAME,
            label: 'step.drop_all_tables.label',
            description: 'step.drop_all_tables.description',
            rank: StepDefinition::RANK_DROP_ALL_TABLES,
            weight: 1,
        );
    }

    public function run(LoadContext $context, StepTracker $tracker): void
    {
        $target = $this->targetDatabaseDsn->targetDatabase();

        $tracker->log(LogTag::Run, \sprintf('Checking that "%s" can be restored into %s.', $context->dump->name, $target->displayName()));

        $this->restorePolicy->ensureRestorable($this->dumpSource->open($context->dump));

        $tracker->log(LogTag::Run, \sprintf('Emptying %s before the restore.', $target->displayName()));

        try {
            $this->database->dropAllTables($target, $tracker);
        } catch (CommandFailed $failure) {
            throw StepFailed::because(\sprintf('%s could not be emptied.', $target->displayName()), $failure);
        }

        $tracker->log(LogTag::Ok, \sprintf('%s no longer holds any table.', $target->displayName()));
    }
}
