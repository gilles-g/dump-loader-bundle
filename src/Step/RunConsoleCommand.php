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

final readonly class RunConsoleCommand implements OptionalStep
{
    public function __construct(
        private ProcessRunner $processes,
        private StepDefinition $definition,
        private ShellCommand $command,
        private bool $enabledByDefault,
    ) {
    }

    public function definition(): StepDefinition
    {
        return $this->definition;
    }

    public function enabledByDefault(): bool
    {
        return $this->enabledByDefault;
    }

    public function run(LoadContext $context, StepTracker $tracker): void
    {
        $tracker->log(LogTag::Run, $this->definition->label);

        try {
            $this->processes->run($this->command, $tracker)->ensureSuccess();
        } catch (CommandFailed $failure) {
            throw StepFailed::because(\sprintf('The "%s" command could not be run.', $this->definition->name), $failure);
        }

        $tracker->log(LogTag::Ok, \sprintf('The "%s" command has been run.', $this->definition->name));
    }
}
