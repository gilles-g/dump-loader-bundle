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

namespace DumpLoader\LoadJob;

/**
 * @phpstan-type SnapshotArray array{
 *     version: int,
 *     id: string,
 *     status: string,
 *     dumpPath: string,
 *     dumpName: string,
 *     dumpSize: int,
 *     options: list<string>,
 *     requester: string|null,
 *     steps: list<array{name: string, label: string, weight: int, status: string, startedAt: string|null, finishedAt: string|null}>,
 *     progress: array{stepNumber: int, stepCount: int, stepPercentage: float},
 *     log: array{lines: list<array{at: string, tag: string, message: string}>, omittedLines: int},
 *     requestedAt: string,
 *     startedAt: string|null,
 *     finishedAt: string|null,
 *     failureReason: string|null,
 * }
 */
final readonly class LoadJobSnapshot
{
    public const int VERSION = 1;

    /**
     * @param list<PlannedStep> $steps
     */
    public function __construct(
        public LoadJobId $id,
        public LoadJobStatus $status,
        public string $dumpPath,
        public string $dumpName,
        public int $dumpSize,
        public LoadOptions $options,
        public Requester $requester,
        public array $steps,
        public Progress $progress,
        public ExecutionLog $log,
        public \DateTimeImmutable $requestedAt,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $finishedAt,
        public ?string $failureReason,
    ) {
    }

    public function elapsedAt(\DateTimeImmutable $now): Duration
    {
        if (null === $this->startedAt) {
            return Duration::zero();
        }

        return Duration::between($this->startedAt, $this->finishedAt ?? $now);
    }

    public function runningStep(): ?PlannedStep
    {
        foreach ($this->steps as $step) {
            if ($step->isRunning()) {
                return $step;
            }
        }

        return null;
    }

    /**
     * @return SnapshotArray
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'id' => $this->id->value,
            'status' => $this->status->value,
            'dumpPath' => $this->dumpPath,
            'dumpName' => $this->dumpName,
            'dumpSize' => $this->dumpSize,
            'options' => $this->options->names,
            'requester' => $this->requester->name,
            'steps' => array_map(static fn (PlannedStep $step): array => $step->toArray(), $this->steps),
            'progress' => $this->progress->toArray(),
            'log' => $this->log->toArray(),
            'requestedAt' => SnapshotDate::format($this->requestedAt),
            'startedAt' => SnapshotDate::format($this->startedAt),
            'finishedAt' => SnapshotDate::format($this->finishedAt),
            'failureReason' => $this->failureReason,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $version = self::readInt($data, 'version');

        if (self::VERSION !== $version) {
            throw new \InvalidArgumentException(\sprintf('Snapshot format version %d is not supported, expected %d.', $version, self::VERSION));
        }

        $requesterName = self::readOptionalString($data, 'requester');

        return new self(
            id: LoadJobId::fromString(self::readString($data, 'id')),
            status: LoadJobStatus::from(self::readString($data, 'status')),
            dumpPath: self::readString($data, 'dumpPath'),
            dumpName: self::readString($data, 'dumpName'),
            dumpSize: self::readInt($data, 'dumpSize'),
            options: LoadOptions::fromNames(self::readStringList($data, 'options')),
            requester: null === $requesterName ? Requester::unknown() : Requester::named($requesterName),
            steps: self::readSteps($data),
            progress: self::readProgress($data),
            log: self::readLog($data),
            requestedAt: SnapshotDate::parse(self::readString($data, 'requestedAt')),
            startedAt: SnapshotDate::parse(self::readOptionalString($data, 'startedAt')),
            finishedAt: SnapshotDate::parse(self::readOptionalString($data, 'finishedAt')),
            failureReason: self::readOptionalString($data, 'failureReason'),
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<PlannedStep>
     */
    private static function readSteps(array $data): array
    {
        $steps = [];

        foreach (self::readArray($data, 'steps') as $step) {
            if (!\is_array($step)) {
                throw self::malformed('steps');
            }

            $steps[] = PlannedStep::fromArray([
                'name' => self::readString($step, 'name'),
                'label' => self::readString($step, 'label'),
                'weight' => self::readInt($step, 'weight'),
                'status' => self::readString($step, 'status'),
                'startedAt' => self::readOptionalString($step, 'startedAt'),
                'finishedAt' => self::readOptionalString($step, 'finishedAt'),
            ]);
        }

        return $steps;
    }

    /**
     * @param array<mixed> $data
     */
    private static function readProgress(array $data): Progress
    {
        $progress = self::readArray($data, 'progress');

        return Progress::fromArray([
            'stepNumber' => self::readInt($progress, 'stepNumber'),
            'stepCount' => self::readInt($progress, 'stepCount'),
            'stepPercentage' => self::readFloat($progress, 'stepPercentage'),
        ]);
    }

    /**
     * @param array<mixed> $data
     */
    private static function readLog(array $data): ExecutionLog
    {
        $log = self::readArray($data, 'log');
        $lines = [];

        foreach (self::readArray($log, 'lines') as $line) {
            if (!\is_array($line)) {
                throw self::malformed('log');
            }

            $lines[] = LogLine::fromArray([
                'at' => self::readString($line, 'at'),
                'tag' => self::readString($line, 'tag'),
                'message' => self::readString($line, 'message'),
            ]);
        }

        return ExecutionLog::fromLines($lines, self::readInt($log, 'omittedLines'));
    }

    /**
     * @param array<mixed> $data
     */
    private static function readString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (!\is_string($value)) {
            throw self::malformed($key);
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function readOptionalString(array $data, string $key): ?string
    {
        if (null === ($data[$key] ?? null)) {
            return null;
        }

        return self::readString($data, $key);
    }

    /**
     * @param array<mixed> $data
     */
    private static function readInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (!\is_int($value)) {
            throw self::malformed($key);
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function readFloat(array $data, string $key): float
    {
        $value = $data[$key] ?? null;

        if (!\is_int($value) && !\is_float($value)) {
            throw self::malformed($key);
        }

        return (float) $value;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function readArray(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (!\is_array($value)) {
            throw self::malformed($key);
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private static function readStringList(array $data, string $key): array
    {
        $values = [];

        foreach (self::readArray($data, $key) as $value) {
            if (!\is_string($value)) {
                throw self::malformed($key);
            }

            $values[] = $value;
        }

        return $values;
    }

    private static function malformed(string $key): \InvalidArgumentException
    {
        return new \InvalidArgumentException(\sprintf('Snapshot field "%s" is missing or malformed.', $key));
    }
}
