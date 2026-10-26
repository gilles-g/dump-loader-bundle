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

namespace DumpLoader\LoadJob;

use DumpLoader\Dump\Dump;
use Psr\Clock\ClockInterface;

final class LoadJob
{
    /**
     * @param list<PlannedStep> $steps
     */
    private function __construct(
        private readonly LoadJobId $id,
        private readonly string $dumpPath,
        private readonly string $dumpName,
        private readonly int $dumpSize,
        private readonly LoadOptions $options,
        private readonly Requester $requester,
        private readonly ClockInterface $clock,
        private LoadJobStatus $status,
        private array $steps,
        private Progress $progress,
        private ExecutionLog $log,
        private readonly \DateTimeImmutable $requestedAt,
        private ?\DateTimeImmutable $startedAt,
        private ?\DateTimeImmutable $finishedAt,
        private ?string $failureReason,
    ) {
    }

    /**
     * @param list<PlannedStep> $steps
     */
    public static function request(
        LoadJobId $id,
        Dump $dump,
        LoadOptions $options,
        array $steps,
        Requester $requester,
        ClockInterface $clock,
    ): self {
        $requestedAt = $clock->now();

        $job = new self(
            id: $id,
            dumpPath: $dump->path,
            dumpName: $dump->name,
            dumpSize: $dump->size->bytes,
            options: $options,
            requester: $requester,
            clock: $clock,
            status: LoadJobStatus::Pending,
            steps: $steps,
            progress: Progress::notStarted(\count($steps)),
            log: ExecutionLog::empty(),
            requestedAt: $requestedAt,
            startedAt: null,
            finishedAt: null,
            failureReason: null,
        );

        $job->append(LogTag::Queued, \sprintf('Load job queued for dump "%s".', $dump->name));

        return $job;
    }

    public static function fromSnapshot(LoadJobSnapshot $snapshot, ClockInterface $clock): self
    {
        return new self(
            id: $snapshot->id,
            dumpPath: $snapshot->dumpPath,
            dumpName: $snapshot->dumpName,
            dumpSize: $snapshot->dumpSize,
            options: $snapshot->options,
            requester: $snapshot->requester,
            clock: $clock,
            status: $snapshot->status,
            steps: $snapshot->steps,
            progress: $snapshot->progress,
            log: $snapshot->log,
            requestedAt: $snapshot->requestedAt,
            startedAt: $snapshot->startedAt,
            finishedAt: $snapshot->finishedAt,
            failureReason: $snapshot->failureReason,
        );
    }

    public function id(): LoadJobId
    {
        return $this->id;
    }

    public function start(): void
    {
        if (LoadJobStatus::Pending !== $this->status) {
            throw InvalidTransition::from($this->status, 'start');
        }

        $this->status = LoadJobStatus::Running;
        $this->startedAt = $this->clock->now();
        $this->append(LogTag::Run, 'Load job started.');
    }

    public function startStep(string $name): void
    {
        $this->ensureRunning('start a step');

        $index = $this->indexOf($name);

        if (StepStatus::Todo !== $this->steps[$index]->status) {
            throw InvalidTransition::stepAlreadyReached($name);
        }

        $this->replaceStep($index, $this->steps[$index]->start($this->clock->now()));
        $this->progress = Progress::atStep($index + 1, \count($this->steps));
        $this->append(LogTag::Run, $this->steps[$index]->label);
    }

    public function progress(Percentage $percentage): void
    {
        $this->ensureRunning('report progress');

        if (null === $this->runningStep()) {
            throw InvalidTransition::noStepRunning('report progress');
        }

        $this->progress = $this->progress->withStepPercentage($percentage);
    }

    public function completeStep(): void
    {
        $this->ensureRunning('complete a step');

        $index = $this->runningStep();

        if (null === $index) {
            throw InvalidTransition::noStepRunning('complete a step');
        }

        $this->replaceStep($index, $this->steps[$index]->complete($this->clock->now()));
        $this->progress = $this->progress->withStepPercentage(Percentage::complete());
        $this->append(LogTag::Ok, $this->steps[$index]->label);
    }

    public function log(LogTag $tag, string $message): void
    {
        if ($this->status->isFinished()) {
            throw InvalidTransition::from($this->status, 'be written to');
        }

        $this->append($tag, $message);
    }

    public function complete(): void
    {
        $this->ensureRunning('complete');

        $this->status = LoadJobStatus::Completed;
        $this->finishedAt = $this->clock->now();
        $this->progress = [] === $this->steps
            ? Progress::notStarted(0)
            : Progress::atStep(\count($this->steps), \count($this->steps))->withStepPercentage(Percentage::complete());
        $this->append(LogTag::Done, 'Load job completed.');
    }

    public function fail(string $reason): void
    {
        $this->ensureActive('fail');

        $this->closeRunningStep(static fn (PlannedStep $step, \DateTimeImmutable $at): PlannedStep => $step->fail($at));
        $this->status = LoadJobStatus::Failed;
        $this->failureReason = $reason;
        $this->finishedAt = $this->clock->now();
        $this->append(LogTag::Error, $reason);
    }

    public function abort(): void
    {
        $this->ensureActive('be aborted');

        $this->closeRunningStep(static fn (PlannedStep $step, \DateTimeImmutable $at): PlannedStep => $step->abort($at));
        $this->status = LoadJobStatus::Aborted;
        $this->finishedAt = $this->clock->now();
        $this->append(LogTag::Stop, 'Load job aborted on request.');
    }

    public function markLost(): void
    {
        $this->ensureActive('be marked lost');

        $this->closeRunningStep(static fn (PlannedStep $step, \DateTimeImmutable $at): PlannedStep => $step->abort($at));
        $this->status = LoadJobStatus::Lost;
        $this->finishedAt = $this->clock->now();
        $this->append(LogTag::Error, 'Load job lost: its process vanished.');
    }

    public function snapshot(): LoadJobSnapshot
    {
        return new LoadJobSnapshot(
            id: $this->id,
            status: $this->status,
            dumpPath: $this->dumpPath,
            dumpName: $this->dumpName,
            dumpSize: $this->dumpSize,
            options: $this->options,
            requester: $this->requester,
            steps: $this->steps,
            progress: $this->progress,
            log: $this->log,
            requestedAt: $this->requestedAt,
            startedAt: $this->startedAt,
            finishedAt: $this->finishedAt,
            failureReason: $this->failureReason,
        );
    }

    private function ensureRunning(string $transition): void
    {
        if (LoadJobStatus::Running !== $this->status) {
            throw InvalidTransition::from($this->status, $transition);
        }
    }

    private function ensureActive(string $transition): void
    {
        if ($this->status->isFinished()) {
            throw InvalidTransition::from($this->status, $transition);
        }
    }

    private function indexOf(string $name): int
    {
        foreach ($this->steps as $index => $step) {
            if ($step->isNamed($name)) {
                return $index;
            }
        }

        throw InvalidTransition::unplannedStep($name);
    }

    private function runningStep(): ?int
    {
        foreach ($this->steps as $index => $step) {
            if ($step->isRunning()) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param callable(PlannedStep, \DateTimeImmutable): PlannedStep $close
     */
    private function closeRunningStep(callable $close): void
    {
        $index = $this->runningStep();

        if (null !== $index) {
            $this->replaceStep($index, $close($this->steps[$index], $this->clock->now()));
        }
    }

    private function replaceStep(int $index, PlannedStep $step): void
    {
        $steps = $this->steps;
        $steps[$index] = $step;

        $this->steps = array_values($steps);
    }

    private function append(LogTag $tag, string $message): void
    {
        $this->log = $this->log->append(new LogLine($this->clock->now(), $tag, $message));
    }
}
