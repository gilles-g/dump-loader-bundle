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

namespace DumpLoader\Tests\Unit\LoadJob;

use DumpLoader\LoadJob\LoadJob;
use DumpLoader\LoadJob\LoadJobSnapshot;
use DumpLoader\LoadJob\LoadJobStatus;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\LoadJob\LogTag;
use DumpLoader\LoadJob\Percentage;
use DumpLoader\LoadJob\Requester;
use DumpLoader\LoadJob\StepStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class LoadJobSnapshotTest extends TestCase
{
    use JobFixtures;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        $now = new \DateTimeImmutable('2026-09-18 10:00:00');

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn($now);

        $this->clock = $clock;
    }

    #[Test]
    public function it_should_carry_its_format_version(): void
    {
        $data = $this->aRunningJob()->snapshot()->toArray();

        self::assertSame(LoadJobSnapshot::VERSION, $data['version']);
        self::assertSame(1, $data['version']);
    }

    #[Test]
    public function it_should_survive_a_round_trip_through_its_array_form(): void
    {
        $snapshot = $this->aRunningJob()->snapshot();

        $rebuilt = LoadJobSnapshot::fromArray($snapshot->toArray());

        self::assertEquals($snapshot, $rebuilt);
    }

    #[Test]
    public function it_should_survive_a_round_trip_through_json(): void
    {
        $snapshot = $this->aRunningJob()->snapshot();

        $decoded = json_decode(json_encode($snapshot->toArray(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            self::fail('A snapshot must encode to a JSON object.');
        }

        $rebuilt = LoadJobSnapshot::fromArray($decoded);

        self::assertEquals($snapshot, $rebuilt);
    }

    #[Test]
    public function it_should_keep_what_the_dashboard_shows_through_the_round_trip(): void
    {
        $snapshot = LoadJobSnapshot::fromArray($this->aRunningJob()->snapshot()->toArray());

        self::assertSame(LoadJobStatus::Running, $snapshot->status);
        self::assertSame('prod-2026-09-18.sql.gz', $snapshot->dumpName);
        self::assertSame(['clear_caches'], $snapshot->options->names);
        self::assertSame('alice', $snapshot->requester->name);
        self::assertSame(StepStatus::Running, $snapshot->steps[0]->status);
        self::assertSame(1, $snapshot->progress->stepNumber);
        self::assertSame(70.0, $snapshot->progress->stepPercentage->value);
        self::assertSame(LogTag::Queued, $snapshot->log->lines[0]->tag);
    }

    #[Test]
    public function it_should_keep_the_failure_reason_and_end_date_of_a_finished_job(): void
    {
        $job = $this->aRunningJob();
        $job->fail('mysql exited with code 1');
        $snapshot = $job->snapshot();

        $rebuilt = LoadJobSnapshot::fromArray($snapshot->toArray());

        self::assertSame(LoadJobStatus::Failed, $rebuilt->status);
        self::assertSame('mysql exited with code 1', $rebuilt->failureReason);
        self::assertEquals($snapshot->finishedAt, $rebuilt->finishedAt);
    }

    #[Test]
    public function it_should_have_no_start_date_on_either_side_of_the_round_trip_for_a_pending_job(): void
    {
        $job = LoadJob::request(
            id: $this->aJobId(),
            dump: $this->aDump(),
            options: LoadOptions::none(),
            steps: $this->plannedSteps(),
            requester: Requester::unknown(),
            clock: $this->clock,
        );

        $rebuilt = LoadJobSnapshot::fromArray($job->snapshot()->toArray());

        self::assertNull($rebuilt->startedAt);
        self::assertNull($rebuilt->finishedAt);
        self::assertNull($rebuilt->failureReason);
        self::assertSame([], $rebuilt->options->names);
        self::assertFalse($rebuilt->requester->isKnown());
    }

    #[Test]
    public function it_should_refuse_an_unsupported_format_version(): void
    {
        $data = $this->aRunningJob()->snapshot()->toArray();
        $data['version'] = LoadJobSnapshot::VERSION + 1;

        $this->expectException(\InvalidArgumentException::class);

        LoadJobSnapshot::fromArray($data);
    }

    #[DataProvider('corruptedSnapshots')]
    #[Test]
    public function it_should_refuse_a_malformed_snapshot(string $key, mixed $value): void
    {
        $data = $this->aRunningJob()->snapshot()->toArray();
        $data[$key] = $value;

        $this->expectException(\InvalidArgumentException::class);

        LoadJobSnapshot::fromArray($data);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function corruptedSnapshots(): iterable
    {
        yield 'id is not a string' => ['id', 42];
        yield 'dumpSize is not an int' => ['dumpSize', 'huge'];
        yield 'options is not an array' => ['options', 'clear_caches'];
        yield 'options holds a non-string' => ['options', [42]];
        yield 'steps holds a scalar' => ['steps', ['restore_database']];
        yield 'progress is not an array' => ['progress', 70.0];
        yield 'progress percentage is not a number' => ['progress', ['stepNumber' => 1, 'stepCount' => 2, 'stepPercentage' => 'half']];
        yield 'log is not an array' => ['log', 'queued'];
        yield 'log holds a scalar line' => ['log', ['lines' => ['queued'], 'omittedLines' => 0]];
        yield 'requestedAt is not a readable date' => ['requestedAt', 'yesterday'];
    }

    #[Test]
    public function it_should_read_the_steps_of_a_snapshot_written_before_steps_were_timed(): void
    {
        $data = $this->aRunningJob()->snapshot()->toArray();
        $data['steps'] = array_map(
            static fn (array $step): array => array_diff_key($step, ['startedAt' => true, 'finishedAt' => true]),
            $data['steps'],
        );

        $rebuilt = LoadJobSnapshot::fromArray($data);

        self::assertSame(StepStatus::Running, $rebuilt->steps[0]->status);
        self::assertNull($rebuilt->steps[0]->startedAt);
    }

    #[Test]
    public function it_should_count_the_seconds_of_a_running_job_until_now(): void
    {
        self::assertSame(90, $this->aRunningJob()->snapshot()->elapsedAt(new \DateTimeImmutable('2026-09-18 10:01:30'))->seconds);
    }

    #[Test]
    public function it_should_count_the_seconds_of_a_finished_job_until_its_end(): void
    {
        $job = $this->aRunningJob();
        $job->completeStep();
        $job->complete();

        self::assertSame(0, $job->snapshot()->elapsedAt(new \DateTimeImmutable('2026-09-18 10:01:30'))->seconds);
    }

    #[Test]
    public function it_should_count_no_second_for_a_job_not_started_yet(): void
    {
        $job = LoadJob::request(
            id: $this->aJobId(),
            dump: $this->aDump(),
            options: LoadOptions::none(),
            steps: $this->plannedSteps(),
            requester: Requester::unknown(),
            clock: $this->clock,
        );

        self::assertSame(0, $job->snapshot()->elapsedAt(new \DateTimeImmutable('2026-09-18 10:01:30'))->seconds);
    }

    private function aRunningJob(): LoadJob
    {
        $job = LoadJob::request(
            id: $this->aJobId(),
            dump: $this->aDump(),
            options: LoadOptions::fromNames(['clear_caches']),
            steps: $this->plannedSteps(),
            requester: Requester::named('alice'),
            clock: $this->clock,
        );
        $job->start();
        $job->startStep('restore_database');
        $job->progress(Percentage::of(70));

        return $job;
    }
}
