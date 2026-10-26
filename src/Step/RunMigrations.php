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

use DumpLoader\Execution\CommandFailed;
use DumpLoader\Execution\ProcessRunner;
use DumpLoader\Execution\ShellCommand;
use DumpLoader\LoadJob\LogTag;

final readonly class RunMigrations implements OptionalStep
{
    public const NAME = 'run_migrations';

    public function __construct(
        private ProcessRunner $processes,
        private string $phpBinary,
        private string $console,
    ) {
    }

    public function definition(): StepDefinition
    {
        return new StepDefinition(
            name: self::NAME,
            label: 'step.run_migrations.label',
            description: 'step.run_migrations.description',
            rank: StepDefinition::RANK_RUN_MIGRATIONS,
            weight: 2,
        );
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function run(LoadContext $context, StepTracker $tracker): void
    {
        $command = new ShellCommand([$this->phpBinary, $this->console, 'doctrine:migrations:migrate', '-n']);

        $tracker->log(LogTag::Run, $command->display());

        try {
            $this->processes->run($command, $tracker)->ensureSuccess();
        } catch (CommandFailed $failure) {
            throw StepFailed::because('The Doctrine migrations could not be run.', $failure);
        }

        $tracker->log(LogTag::Ok, 'The Doctrine migrations have been run.');
    }
}
