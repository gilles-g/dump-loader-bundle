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

namespace DumpLoader\Step;

use DumpLoader\Database\DatabaseClient;
use DumpLoader\Database\TargetDatabaseDsn;
use DumpLoader\Dump\DumpSource;
use DumpLoader\LoadJob\LogTag;

final readonly class RestoreDatabase implements Step
{
    public const NAME = 'restore_database';

    public function __construct(
        private DumpSource $dumps,
        private DatabaseClient $database,
        private TargetDatabaseDsn $targetDatabaseDsn,
    ) {
    }

    public function definition(): StepDefinition
    {
        return new StepDefinition(
            name: self::NAME,
            label: 'step.restore_database.label',
            description: 'step.restore_database.description',
            rank: StepDefinition::RANK_RESTORE_DATABASE,
            weight: 10,
        );
    }

    public function run(LoadContext $context, StepTracker $tracker): void
    {
        $target = $this->targetDatabaseDsn->targetDatabase();

        $tracker->log(LogTag::Run, \sprintf('Restoring "%s" into %s.', $context->dump->name, $target->displayName()));

        $this->database->restore($target, $this->dumps->open($context->dump), $tracker);

        $tracker->log(LogTag::Ok, \sprintf('"%s" has been restored.', $context->dump->name));
    }
}
