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

final readonly class ClearCaches implements OptionalStep
{
    public const NAME = 'clear_caches';

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
            label: 'step.clear_caches.label',
            description: 'step.clear_caches.description',
            rank: StepDefinition::RANK_CLEAR_CACHES,
            weight: 1,
        );
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function run(LoadContext $context, StepTracker $tracker): void
    {
        $command = new ShellCommand([$this->phpBinary, $this->console, 'cache:clear', '-n']);

        $tracker->log(LogTag::Run, $command->display());

        try {
            $this->processes->run($command, $tracker)->ensureSuccess();
        } catch (CommandFailed $failure) {
            throw StepFailed::because('The application cache could not be cleared.', $failure);
        }

        $tracker->log(LogTag::Ok, 'The application cache has been cleared.');
    }
}
