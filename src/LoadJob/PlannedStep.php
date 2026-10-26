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

use DumpLoader\Step\StepDefinition;

final readonly class PlannedStep
{
    public function __construct(
        public string $name,
        public string $label,
        public int $weight,
        public StepStatus $status,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $finishedAt = null,
    ) {
        if ('' === $this->name) {
            throw new \InvalidArgumentException('A planned step needs a name.');
        }

        if ($this->weight < 1) {
            throw new \InvalidArgumentException(\sprintf('The weight of step "%s" must be at least 1, %d given.', $this->name, $this->weight));
        }
    }

    public static function fromDefinition(StepDefinition $definition): self
    {
        return new self(
            name: $definition->name,
            label: $definition->label,
            weight: $definition->weight,
            status: StepStatus::Todo,
        );
    }

    public function start(\DateTimeImmutable $at): self
    {
        return new self(
            name: $this->name,
            label: $this->label,
            weight: $this->weight,
            status: StepStatus::Running,
            startedAt: $at,
        );
    }

    public function complete(\DateTimeImmutable $at): self
    {
        return $this->endedAs(StepStatus::Done, $at);
    }

    public function fail(\DateTimeImmutable $at): self
    {
        return $this->endedAs(StepStatus::Failed, $at);
    }

    public function abort(\DateTimeImmutable $at): self
    {
        return $this->endedAs(StepStatus::Aborted, $at);
    }

    public function isNamed(string $name): bool
    {
        return $this->name === $name;
    }

    public function isRunning(): bool
    {
        return StepStatus::Running === $this->status;
    }

    public function elapsedAt(\DateTimeImmutable $now): ?Duration
    {
        if (null === $this->startedAt) {
            return null;
        }

        return Duration::between($this->startedAt, $this->finishedAt ?? $now);
    }

    /**
     * @return array{name: string, label: string, weight: int, status: string, startedAt: string|null, finishedAt: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'weight' => $this->weight,
            'status' => $this->status->value,
            'startedAt' => SnapshotDate::format($this->startedAt),
            'finishedAt' => SnapshotDate::format($this->finishedAt),
        ];
    }

    /**
     * @param array{name: string, label: string, weight: int, status: string, startedAt: string|null, finishedAt: string|null} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            label: $data['label'],
            weight: $data['weight'],
            status: StepStatus::from($data['status']),
            startedAt: SnapshotDate::parse($data['startedAt']),
            finishedAt: SnapshotDate::parse($data['finishedAt']),
        );
    }

    private function endedAs(StepStatus $status, \DateTimeImmutable $at): self
    {
        return new self(
            name: $this->name,
            label: $this->label,
            weight: $this->weight,
            status: $status,
            startedAt: $this->startedAt,
            finishedAt: $at,
        );
    }
}
